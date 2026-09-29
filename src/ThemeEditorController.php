<?php

namespace Dynart\ThemeEditor;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Repository\RepositoryFiles;
use Dynart\Dpress\Repository\RepositoryStatus;
use Dynart\Dpress\Theme\ThemeService;

/**
 * The Theme files screen - the active theme's files - and the editor for one of them
 *
 * Every action is behind `theme.edit`, reading included: a template's source is not a secret, but
 * nobody without the permission has a reason to be here, and one rule is simpler than two.
 */
class ThemeEditorController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected ThemeService $themes,
        protected RepositoryStatus $status,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return ThemeEditor::ADMIN_SECTION;
    }

    /** The active theme's files, or none when the site renders with the built-in templates */
    protected function files(): ThemeFiles {
        $theme = $this->themes->active();
        return new ThemeFiles($theme === '' ? '' : $this->themes->path().'/'.$theme);
    }

    /** The clone the theme is in - searched from its folder up to the site's root */
    protected function cloneOf(ThemeFiles $files): string {
        return $files->folder() === '' ? '' : ThemeFiles::cloneOf($files->folder(), $this->config->rootPath());
    }

    #[Route('GET', '/admin/theme-editor')]
    public function index(): string {
        $this->requirePermission(ThemeEditor::PERMISSION_EDIT);
        $files = $this->files();
        $clone = $this->cloneOf($files);
        $changes = $files->changes($this->status, $clone) ?? [];
        $rows = [];
        foreach ($files->list() as $path => $file) {
            $rows[] = $file + [
                'path'     => $path,
                'state'    => $changes[$path] ?? '',
                'edit_url' => $this->router->url('/admin/theme-editor/edit', ['file' => $path]),
            ];
        }
        return $this->admin('theme_editor:admin/index', [
            'title'   => 'Theme files',
            'theme'   => $this->themes->active(),
            'folder'  => $files->folder(),
            'clone'   => $clone,
            'rows'    => $rows,
            'changes' => $changes,
        ]);
    }

    /**
     * One file in the code editor
     *
     * The file is looked up in the theme's list (`ThemeFiles::list()`) before anything opens it,
     * so `?file=` is a name to find and never a path to follow.
     */
    #[Route('GET', '/admin/theme-editor/edit')]
    #[Route('POST', '/admin/theme-editor/edit')]
    public function edit(): string {
        $this->requirePermission(ThemeEditor::PERMISSION_EDIT);
        $files = $this->files();
        $path = (string)$this->request->get('file', '');
        $listed = $files->list();
        if (!isset($listed[$path])) {
            $this->app()->sendError(404);
            return '';
        }
        // registered here and not while loading, like the Docs plugin's: the form factory is the
        // web app's, and a plugin that asks for it while loading fails in every `dpress` command
        if (!$this->forms->has(ThemeEditor::FORM_FILE)) {
            $this->forms->add(ThemeEditor::FORM_FILE, [ThemeEditorForms::class, 'file']);
        }
        $text = $files->read($path);
        $form = $this->forms->create(ThemeEditor::FORM_FILE, [
            'text'     => $text,
            'hash'     => RepositoryFiles::hash($text),
            'language' => $listed[$path]['language'],
        ]);
        if ($form->process()) {
            $values = $form->values();
            try {
                $written = $files->write($path, (string)$values['text'], (string)$values['hash']);
            } catch (DpressException $e) {
                $form->addFieldError('text', $e->getMessage());
                return $this->editor($files, $path, $form);
            }
            if ($written) {
                self::forgetCompiled($files->folder().'/'.$path);
            }
            $this->done('/admin/theme-editor/edit', $written ? 'Saved.' : 'Nothing changed.', ['file' => $path]);
            return '';
        }
        return $this->editor($files, $path, $form);
    }

    protected function editor(ThemeFiles $files, string $path, $form): string {
        $changes = $files->changes($this->status, $this->cloneOf($files));
        return $this->admin('theme_editor:admin/edit', [
            'title'    => basename($path),
            'path'     => $path,
            'form'     => $form,
            'in_git'   => $changes !== null,
            'state'    => $changes[$path] ?? '',
            'back_url' => $this->router->url('/admin/theme-editor'),
        ]);
    }

    /**
     * A saved template is the one the next request runs
     *
     * With OPcache set not to check file times - a common production setting - PHP would go on
     * running the compiled old template until a restart. A stylesheet needs nothing: its address
     * carries the file's time.
     */
    public static function forgetCompiled(string $file): void {
        if (ThemeFiles::isPhp($file) && function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
