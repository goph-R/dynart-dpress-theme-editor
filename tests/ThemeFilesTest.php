<?php

namespace Dynart\ThemeEditor\Test;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Repository\Git;
use Dynart\Dpress\Repository\RepositoryFiles;
use Dynart\Dpress\Repository\RepositoryStatus;
use Dynart\ThemeEditor\ThemeFiles;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Which files of a theme the editor opens, and what it refuses to write
 *
 * @covers \Dynart\ThemeEditor\ThemeFiles
 */
final class ThemeFilesTest extends TestCase {

    private string $root = '';

    protected function setUp(): void {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/theme-editor-'.bin2hex(random_bytes(4));
        $theme = $this->root.'/site/themes/plain';
        mkdir($theme.'/dpress/content', 0777, true);
        mkdir($theme.'/assets', 0777, true);
        mkdir($theme.'/.git-stuff', 0777, true);
        mkdir($theme.'/node_modules/x', 0777, true);
        file_put_contents($theme.'/theme.ini', "title = \"Plain\"\n");
        file_put_contents($theme.'/dpress/content/list.phtml', "<?php foreach (\$posts as \$post): ?>\n<p><?= esc_html(\$post['title']) ?></p>\n<?php endforeach ?>\n");
        file_put_contents($theme.'/assets/style.css', "body { color: red; }\n");
        file_put_contents($theme.'/assets/logo.png', 'not text');
        file_put_contents($theme.'/.git-stuff/secret.phtml', 'hidden');
        file_put_contents($theme.'/node_modules/x/lib.js', 'vendored');
        file_put_contents($theme.'/assets/big.js', str_repeat('x', ThemeFiles::MAX_BYTES + 1));
    }

    protected function tearDown(): void {
        if ($this->root !== '' && is_dir($this->root)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                @chmod($file->getPathname(), 0777);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    private function files(): ThemeFiles {
        return new ThemeFiles($this->root.'/site/themes/plain');
    }

    // --- what is listed ---

    public function testTheSourceFilesAreListedWithTheirLanguage(): void {
        $this->assertSame(
            ['assets/style.css' => 'css', 'dpress/content/list.phtml' => 'html', 'theme.ini' => 'ini'],
            array_map(fn(array $file) => $file['language'], $this->files()->list())
        );
    }

    /** a picture, a hidden folder, a vendored library and a minified bundle are not source */
    public function testWhatIsNotSourceIsNotListed(): void {
        $listed = array_keys($this->files()->list());
        foreach (['assets/logo.png', '.git-stuff/secret.phtml', 'node_modules/x/lib.js', 'assets/big.js'] as $path) {
            $this->assertNotContains($path, $listed, $path);
        }
    }

    public function testNoThemeHasNoFiles(): void {
        $this->assertSame([], (new ThemeFiles(''))->list());
    }

    /** the list is the gate: a path is found on it, never followed */
    public function testOnlyAListedFileOpens(): void {
        foreach (['../../../dpress.ini', 'assets/logo.png', '.git-stuff/secret.phtml', 'nope.css'] as $path) {
            try {
                $this->files()->read($path);
                $this->fail("$path was opened");
            } catch (DpressException $e) {
                $this->assertStringContainsString('not a file of the theme', $e->getMessage());
            }
        }
        $this->assertStringContainsString('color: red', $this->files()->read('assets/style.css'));
    }

    // --- what is written ---

    public function testAStylesheetIsWritten(): void {
        $files = $this->files();
        $opened = RepositoryFiles::hash($files->read('assets/style.css'));
        $this->assertTrue($files->write('assets/style.css', "body { color: blue; }", $opened));
        $this->assertSame("body { color: blue; }\n", file_get_contents($this->root.'/site/themes/plain/assets/style.css'));
    }

    /** a template that does not parse would take every page that uses it down */
    public function testATemplateThatDoesNotParseIsNotWritten(): void {
        $files = $this->files();
        $before = $files->read('dpress/content/list.phtml');
        try {
            $files->write('dpress/content/list.phtml', "<?php foreach (\$posts as \$post): ?>\n<p><?= esc_html(\$post['title'] ?></p>\n", RepositoryFiles::hash($before));
            $this->fail('the broken template was written');
        } catch (DpressException $e) {
            $this->assertStringContainsString('PHP cannot parse this', $e->getMessage());
            $this->assertStringContainsString('line 2', $e->getMessage());
        }
        $this->assertSame($before, $files->read('dpress/content/list.phtml'));
    }

    public function testParsingIsForPhpOnly(): void {
        $this->assertTrue(ThemeFiles::isPhp('a/list.phtml'));
        $this->assertTrue(ThemeFiles::isPhp('functions.PHP'));
        $this->assertFalse(ThemeFiles::isPhp('style.css'));
        $this->assertNull(ThemeFiles::parseError("<p><?= \$x ?></p>"));
        // a stylesheet with a `<?` in a comment is not PHP's business
        $files = $this->files();
        $opened = RepositoryFiles::hash($files->read('assets/style.css'));
        $this->assertTrue($files->write('assets/style.css', "/* <?php ( */ body {}", $opened));
    }

    // --- the clone it is in ---

    public function testTheCloneIsFoundAboveTheTheme(): void {
        mkdir($this->root.'/site/.git');
        $this->assertSame($this->root.'/site', ThemeFiles::cloneOf($this->root.'/site/themes/plain', $this->root.'/site'));
    }

    /** not above the site's root - a clone of somebody's home folder is not this site's */
    public function testTheSearchStopsAtTheSitesRoot(): void {
        mkdir($this->root.'/.git');
        $this->assertSame('', ThemeFiles::cloneOf($this->root.'/site/themes/plain', $this->root.'/site'));
    }

    /** a theme folder that is its own clone - one installed with `git clone` */
    public function testAThemeCanBeItsOwnClone(): void {
        mkdir($this->root.'/site/themes/plain/.git');
        $this->assertSame($this->root.'/site/themes/plain', ThemeFiles::cloneOf($this->root.'/site/themes/plain', $this->root.'/site'));
    }

    /** the clone answers for the whole site; the theme wants its own files, by its own paths */
    public function testTheChangesAreTheThemesByItsOwnPaths(): void {
        exec('git --version', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git is not installed');
        }
        $site = $this->root.'/site';
        exec("git init -q -b main $site && git -C $site add -A && git -C $site -c user.name=t -c user.email=t@t commit -q -m first 2>&1", $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        file_put_contents($site.'/themes/plain/assets/style.css', "body { color: green; }\n");
        file_put_contents($site.'/README.md', "not the theme's\n");
        exec("git -C $site add README.md 2>&1");
        $changes = $this->files()->changes(new RepositoryStatus(new Git()), $site);
        $this->assertSame(['assets/style.css' => RepositoryStatus::UNCOMMITTED], $changes);
        $this->assertNull($this->files()->changes(new RepositoryStatus(new Git()), ''));
    }
}
