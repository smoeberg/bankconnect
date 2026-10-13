<?php

use PHPUnit\Framework\TestCase;

final class SingleBankLineIngestionPathTest extends TestCase
{
    /**
     * A bank entry must only be created through DolibarrBankEntryService.
     *
     * This is a negative architectural regression test: if a legacy route
     * reintroduces another Account::addline() call or direct SQL insertion
     * into llx_bank, the test fails before that route can ship.
     */
    public function testOnlyTheAuthoritativeServiceCanCreateBankEntries(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $moduleRoot = $repoRoot.'/htdocs/custom/bankconnect';
        $authoritativeFile = $moduleRoot.'/class/DolibarrBankEntryService.php';

        self::assertFileExists(
            $authoritativeFile,
            'The authoritative bank-entry service must exist.'
        );

        $addlineCallSites = [];
        $directBankWrites = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $moduleRoot,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $source = file_get_contents($path);

            self::assertNotFalse($source, 'Unable to read '.$path);

            $relativePath = substr($path, strlen($repoRoot) + 1);

            // Detect actual PHP method calls, not comments or documentation.
            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) {
                    continue;
                }

                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }

                if ($j >= $count || !is_array($tokens[$j])
                    || strtolower($tokens[$j][1]) !== 'addline') {
                    continue;
                }

                $k = $j + 1;
                while ($k < $count && is_array($tokens[$k])
                    && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }

                if ($k < $count && $tokens[$k] === '(') {
                    $addlineCallSites[] = $relativePath;
                }
            }

            // Detect direct SQL INSERT/REPLACE statements targeting llx_bank,
            // including the common MAIN_DB_PREFIX/$prefix concatenation forms.
            $bankWritePattern =
                '/\b(?:INSERT|REPLACE)\s+INTO\s*'
                .'(?:[\'"]\s*\.\s*\$?(?:prefix|conf->db->prefix)\s*\.\s*[\'"])?'
                .'(?:llx_bank\b|bank\b)/i';

            if (preg_match($bankWritePattern, $source) === 1) {
                $directBankWrites[] = $relativePath;
            }
        }

        self::assertSame(
            [$this->relativePath($repoRoot, $authoritativeFile)],
            array_values(array_unique($addlineCallSites)),
            'Account::addline() must only be called by DolibarrBankEntryService. '
            .'A legacy ingestion route could otherwise create a second bank line.'
        );

        self::assertSame(
            [],
            $directBankWrites,
            'No module PHP file may insert or replace rows directly in llx_bank. '
            .'Use the authoritative Dolibarr bank-entry service.'
        );
    }

    private function relativePath(string $root, string $path): string
    {
        return substr($path, strlen($root) + 1);
    }
}
