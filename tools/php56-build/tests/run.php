<?php

declare(strict_types=1);

use WordPress\Reprint\Build\Php56SyntaxValidator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$tool_root = dirname(__DIR__);
$fixture_root = __DIR__ . '/Fixture';
$temporary_root = sys_get_temp_dir() . '/reprint-php56-rector-' . getmypid();
if (!mkdir($temporary_root, 0700) && !is_dir($temporary_root)) {
    fail_test('Could not create the temporary fixture directory.');
}

try {
    $input = $fixture_root . '/php72-syntax.input.php';
    $generated = $temporary_root . '/php72-syntax.php';
    copy($input, $generated);

    $validator = new Php56SyntaxValidator();
    try {
        $validator->assertFile($generated);
        fail_test('The PHP 7.2 fixture unexpectedly passed the pre-downgrade syntax check.');
    } catch (RuntimeException $runtime_exception) {
        if (strpos($runtime_exception->getMessage(), 'return type declaration') === false) {
            throw $runtime_exception;
        }
    }

    run_rector($tool_root, $generated, true);
    $validator->assertFile($generated);

    $expected = $fixture_root . '/php72-syntax.expected.php';
    if (!is_file($expected)) {
        fail_test(sprintf('Missing expected Rector fixture %s.', $expected));
    }
    assert_same_file($expected, $generated);

    $input_output = run_php($input);
    $generated_output = run_php($generated);
    if ($input_output !== $generated_output) {
        fail_test(sprintf(
            "The generated fixture changed behavior.\nInput: %s\nGenerated: %s",
            $input_output,
            $generated_output
        ));
    }

    $unsupported = $temporary_root . '/unsupported-coalesce.php';
    copy($fixture_root . '/unsupported-coalesce.input.php', $unsupported);
    $unsupported_output = run_rector($tool_root, $unsupported, false);
    if (strpos($unsupported_output, 'Cannot safely downgrade the null-coalescing expression') === false) {
        fail_test("The unsupported null-coalescing fixture did not fail with the expected message.\n" . $unsupported_output);
    }

    $php70_input = $fixture_root . '/php70-target.input.php';
    $php70_generated = $temporary_root . '/php70-target.php';
    copy($php70_input, $php70_generated);

    $php70_validator = new Php56SyntaxValidator('7.0');
    try {
        $php70_validator->assertFile($php70_generated);
        fail_test('The PHP 7.0 fixture unexpectedly passed the pre-downgrade syntax check.');
    } catch (RuntimeException $runtime_exception) {
        // The fixture's private class constant precedes its typed method in
        // traversal order, so that is the first node this check rejects.
        if (strpos($runtime_exception->getMessage(), 'class-constant modifier') === false) {
            throw $runtime_exception;
        }
    }

    run_rector($tool_root, $php70_generated, true, 'rector-php70.php');
    $php70_validator->assertFile($php70_generated);

    $php70_expected = $fixture_root . '/php70-target.expected.php';
    if (!is_file($php70_expected)) {
        fail_test(sprintf('Missing expected Rector fixture %s.', $php70_expected));
    }
    assert_same_file($php70_expected, $php70_generated);

    $php70_input_output = run_php($php70_input);
    $php70_generated_output = run_php($php70_generated);
    if ($php70_input_output !== $php70_generated_output) {
        fail_test(sprintf(
            "The generated PHP 7.0 fixture changed behavior.\nInput: %s\nGenerated: %s",
            $php70_input_output,
            $php70_generated_output
        ));
    }

    try {
        (new Php56SyntaxValidator())->assertFile($php70_generated);
        fail_test('The PHP 7.0 fixture unexpectedly passed the PHP 5.6 syntax check.');
    } catch (RuntimeException $runtime_exception) {
        if (strpos($runtime_exception->getMessage(), 'null-coalescing') === false) {
            throw $runtime_exception;
        }
    }

    $nullable = $temporary_root . '/php70-nullable-param.php';
    file_put_contents($nullable, "<?php\nfunction php70_nullable(?array \$options)\n{\n    return \$options;\n}\n");
    try {
        $php70_validator->assertFile($nullable);
        fail_test('The nullable-parameter fixture unexpectedly passed the PHP 7.0 syntax check.');
    } catch (RuntimeException $runtime_exception) {
        if (strpos($runtime_exception->getMessage(), 'PHP 7.0 cannot parse') === false) {
            throw $runtime_exception;
        }
    }

    $scalar = $temporary_root . '/php70-scalar-param.php';
    file_put_contents($scalar, "<?php\nfunction php70_scalar(int \$limit, Throwable \$error)\n{\n    return \$limit;\n}\n");
    $php70_validator->assertFile($scalar);
    try {
        (new Php56SyntaxValidator())->assertFile($scalar);
        fail_test('The scalar-parameter fixture unexpectedly passed the PHP 5.6 syntax check.');
    } catch (RuntimeException $runtime_exception) {
        if (strpos($runtime_exception->getMessage(), 'PHP 5.6 cannot parse') === false) {
            throw $runtime_exception;
        }
    }

    echo "PHP 5.6 and 7.0 Rector fixtures passed.\n";
} finally {
    remove_tree($temporary_root);
}

function run_rector(string $tool_root, string $path, bool $must_succeed, string $config = 'rector.php'): string
{
    $command = escapeshellarg($tool_root . '/vendor/bin/rector')
        . ' process '
        . escapeshellarg($path)
        . ' --config '
        . escapeshellarg($tool_root . '/' . $config)
        . ' --no-progress-bar --clear-cache 2>&1';
    exec($command, $lines, $status);
    $output = implode("\n", $lines);
    if ($must_succeed && $status !== 0) {
        fail_test(sprintf("Rector failed with exit code %d.\n%s", $status, $output));
    }
    if (!$must_succeed && $status === 0) {
        fail_test('Rector unexpectedly accepted an unsafe null-coalescing expression.');
    }

    return $output;
}

function run_php(string $path): string
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $lines, $status);
    $output = implode("\n", $lines);
    if ($status !== 0) {
        fail_test(sprintf("Fixture execution failed with exit code %d.\n%s", $status, $output));
    }

    return $output;
}

function assert_same_file(string $expected, string $actual): void
{
    $expected_contents = str_replace("\r\n", "\n", (string) file_get_contents($expected));
    $actual_contents = str_replace("\r\n", "\n", (string) file_get_contents($actual));
    if ($expected_contents === $actual_contents) {
        return;
    }

    fail_test(sprintf('Generated fixture does not match %s.', $expected));
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
