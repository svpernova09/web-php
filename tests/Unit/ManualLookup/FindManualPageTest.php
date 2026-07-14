<?php

declare(strict_types=1);

namespace {
    // include/manual-lookup.inc defines global functions and depends on the global
    // get_manual_search_sections() (normally from include/site.inc). Provide the same
    // section list here so the file can be exercised without pulling in all of site.inc.
    if (!function_exists('get_manual_search_sections')) {
        function get_manual_search_sections(): array
        {
            return ['', 'book.', 'ref.', 'function.', 'class.', 'enum.', 'features.', 'control-structures.', 'language.', 'about.', 'faq.'];
        }
    }

    require_once __DIR__ . '/../../../include/manual-lookup.inc';
}

namespace phpweb\Test\Unit\ManualLookup {

    use PHPUnit\Framework;

    #[Framework\Attributes\CoversFunction('find_manual_page')]
    #[Framework\Attributes\CoversFunction('find_manual_page_slow')]
    final class FindManualPageTest extends Framework\TestCase
    {
        private string $root;

        private ?string $originalDocumentRoot;

        protected function setUp(): void
        {
            $this->root = sys_get_temp_dir() . '/phpweb-ml-' . uniqid('', true);
            mkdir($this->root . '/backend', 0777, true);
            mkdir($this->root . '/manual/en', 0777, true);
            // Filesystem (slow-path) target for the keyword "echo".
            file_put_contents($this->root . '/manual/en/function.echo.php', '<?php');

            $this->originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
            $_SERVER['DOCUMENT_ROOT'] = $this->root;
        }

        protected function tearDown(): void
        {
            if ($this->originalDocumentRoot === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $this->originalDocumentRoot;
            }

            array_map('unlink', glob($this->root . '/backend/*') ?: []);
            array_map('unlink', glob($this->root . '/manual/en/*') ?: []);
            @rmdir($this->root . '/backend');
            @rmdir($this->root . '/manual/en');
            @rmdir($this->root . '/manual');
            @rmdir($this->root);
        }

        /**
         * Regression test for the production fatal:
         *   Uncaught PDOException: SQLSTATE[HY000]: General error: 8
         *   attempt to write a readonly database in include/manual-lookup.inc
         *
         * When the sqlite fast-path fails for ANY reason (a read-only/locked database,
         * or a corrupt/truncated one from an interrupted rsync), find_manual_page() must
         * fall back to the filesystem search instead of throwing an uncaught exception.
         */
        public function testFallsBackToSlowSearchWhenSqliteQueryFails(): void
        {
            file_put_contents($this->root . '/backend/manual-lookup.sqlite', 'this is not a sqlite database');

            $result = find_manual_page('en', 'echo');

            self::assertSame('/manual/en/function.echo.php', $result);
        }

        public function testFallsBackToSlowSearchForDottedKeywordWhenSqliteQueryFails(): void
        {
            // A dotted keyword takes the other SQL branch in find_manual_page(); it must
            // fall back to the filesystem search on a broken database too.
            file_put_contents($this->root . '/backend/manual-lookup.sqlite', 'this is not a sqlite database');

            $result = find_manual_page('en', 'function.echo');

            self::assertSame('/manual/en/function.echo.php', $result);
        }

        #[Framework\Attributes\RequiresPhpExtension('pdo_sqlite')]
        public function testUsesSqliteFastPathWhenDatabaseIsValid(): void
        {
            $this->buildValidDatabase();

            $result = find_manual_page('en', 'function.echo');

            self::assertSame('/manual/en/function.echo.php', $result);
        }

        public function testFallsBackToSlowSearchWhenNoDatabasePresent(): void
        {
            // No backend/manual-lookup.sqlite at all -> slow (filesystem) search only.
            $result = find_manual_page('en', 'echo');

            self::assertSame('/manual/en/function.echo.php', $result);
        }

        private function buildValidDatabase(): void
        {
            $dbh = new \PDO('sqlite:' . $this->root . '/backend/manual-lookup.sqlite');
            $dbh->exec('CREATE TABLE fs (lang TEXT, prefix TEXT, keyword TEXT, name TEXT, prio INT)');
            $dbh->exec("INSERT INTO fs (lang, prefix, keyword, name, prio) VALUES ('en', 'function.', 'echo', '/manual/en/function.echo.php', 3)");
            $dbh = null;
        }
    }
}
