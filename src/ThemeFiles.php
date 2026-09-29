<?php

namespace Dynart\ThemeEditor;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Repository\RepositoryFiles;
use Dynart\Dpress\Repository\RepositoryStatus;

/**
 * The files of one theme the editor may open, and the checks around writing them
 *
 * **Only what is listed can be opened.** `list()` walks the theme's folder for the kinds of file a
 * theme is written in, and the editor opens a path only when it is on that list - so a query
 * string can never name `../../dpress.ini`, a hidden folder or a picture. Writing then goes
 * through Dpress's `RepositoryFiles`, which keeps the file's line endings and refuses to write
 * over a file that changed since it was opened.
 *
 * **A template that does not parse is not written.** A `.phtml` with a missing `?>` or an unclosed
 * bracket takes every page that uses it down with a 500, so PHP is asked to parse the text first
 * (`token_get_all(..., TOKEN_PARSE)`, the same parser `php -l` uses, in this process) and a parse
 * error is a message on the field instead.
 */
class ThemeFiles {

    /** The kinds of file a theme is written in, and the editor's language for each */
    const TYPES = [
        'phtml' => 'html',
        'php'   => 'html',
        'html'  => 'html',
        'css'   => 'css',
        'md'    => 'markdown',
        'js'    => 'text',
        'json'  => 'text',
        'ini'   => 'text',
        'txt'   => 'text',
    ];

    /** Larger than this is not something anybody edits in a browser - a minified library */
    const MAX_BYTES = 512 * 1024;

    /** Folders a theme keeps things in that are not its source */
    const SKIPPED = ['node_modules', 'vendor'];

    public function __construct(protected string $folder) {}

    public function folder(): string {
        return $this->folder;
    }

    /**
     * Every file of the theme the editor may open, relative to its folder with `/`, sorted
     *
     * @return array<string, array{language: string, size: int}>
     */
    public function list(): array {
        $found = [];
        if ($this->folder === '' || !is_dir($this->folder)) {
            return $found;
        }
        $this->walk($this->folder, '', $found, 0);
        ksort($found, SORT_NATURAL | SORT_FLAG_CASE);
        return $found;
    }

    protected function walk(string $dir, string $prefix, array &$found, int $depth): void {
        if ($depth > 8) {
            return;
        }
        foreach ((array)scandir($dir) as $entry) {
            // hidden files and folders - `.git` above all - and the ones that are not source
            if ($entry === '' || $entry[0] === '.' || in_array($entry, self::SKIPPED, true)) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_link($path)) {
                continue;   // a link can lead out of the theme
            }
            if (is_dir($path)) {
                $this->walk($path, $prefix.$entry.'/', $found, $depth + 1);
                continue;
            }
            $language = self::languageOf($entry);
            if ($language !== null && filesize($path) <= self::MAX_BYTES) {
                $found[$prefix.$entry] = ['language' => $language, 'size' => (int)filesize($path)];
            }
        }
    }

    /** The editor's language for a file name - `data-code` - or null when it is not editable */
    public static function languageOf(string $name): ?string {
        return self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
    }

    /** Whether a file is one PHP runs - so its text is parsed before it is written */
    public static function isPhp(string $name): bool {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['php', 'phtml'], true);
    }

    /**
     * Why a template would not run, or null when it parses
     *
     * `TOKEN_PARSE` makes the tokenizer run the real parser, so a syntax error is a `ParseError`
     * with its line - the answer `php -l` gives, without starting a process (and in PHP-FPM there
     * is no CLI binary to start).
     */
    public static function parseError(string $text): ?string {
        try {
            token_get_all($text, TOKEN_PARSE);
            return null;
        } catch (\ParseError $e) {
            return 'PHP cannot parse this, so it was not saved: '.$e->getMessage().' on line '.$e->getLine().'.';
        }
    }

    public function read(string $path): string {
        $this->assertListed($path);
        return (new RepositoryFiles($this->folder))->read($path);
    }

    /**
     * Writes a file of the theme, parsed first when PHP runs it
     *
     * @return bool whether anything was written
     * @throws DpressException a file not on the list, text that does not parse, or one that
     *                         changed since it was opened - with a message for the field
     */
    public function write(string $path, string $text, string $opened): bool {
        $this->assertListed($path);
        if (self::isPhp($path) && ($error = self::parseError($text)) !== null) {
            throw new DpressException($error);
        }
        return (new RepositoryFiles($this->folder))->write($path, $text, $opened);
    }

    protected function assertListed(string $path): void {
        if (!isset($this->list()[$path])) {
            throw new DpressException("$path is not a file of the theme this editor opens.");
        }
    }

    /**
     * The git clone the theme is in, or '' - the theme folder itself, or a folder above it, up to
     * `$stop` (the site's root) and no further
     *
     * A theme is usually a folder *inside* the site's repository (gopherlab's is `themes/gopherlab`
     * of the site's clone), and `RepositoryStatus` asks the clone.
     */
    public static function cloneOf(string $folder, string $stop): string {
        $dir = rtrim(str_replace('\\', '/', $folder), '/');
        $stop = rtrim(str_replace('\\', '/', $stop), '/');
        // only while inside `$stop` - a case-insensitive prefix, for a Windows path
        while ($dir !== '' && ($stop === '' || stripos($dir.'/', $stop.'/') === 0)) {
            if (file_exists($dir.'/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir || strcasecmp($dir, $stop) === 0) {
                break;
            }
            $dir = $parent;
        }
        return '';
    }

    /**
     * The theme's files that differ from the remote, as paths of the theme
     *
     * @return array<string, string>|null path => `RepositoryStatus` state; null when the theme is
     *                                     in no git clone, and there is nothing to say
     */
    public function changes(RepositoryStatus $status, string $clone): ?array {
        if ($clone === '') {
            return null;
        }
        $all = $status->changes($clone);
        if ($all === null) {
            return null;
        }
        $folder = rtrim(str_replace('\\', '/', $this->folder), '/');
        $prefix = strlen($folder) > strlen($clone) ? substr($folder, strlen($clone) + 1).'/' : '';
        $mine = [];
        foreach ($all as $path => $state) {
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                $mine[substr($path, strlen($prefix))] = $state;
            }
        }
        return $mine;
    }
}
