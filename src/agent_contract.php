<?php declare(strict_types=1);
namespace TinyTest;

// Shared by local-process and remote-HTTP tools; declaration-only, no host interface.
function agent_tool_definition(string $description): array
{
    return [
        'type' => 'function',
        'name' => 'tinytest_run',
        'description' => $description,
        'strict' => true,
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'directory' => ['type' => ['string', 'null'], 'description' => 'Project-relative directory; shallow test_*.php discovery. Null when files are supplied.'],
                'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Project-relative PHP files. Empty when directory is supplied.'],
                'test' => ['type' => ['string', 'null'], 'description' => 'Exact global test function name, or null for all.'],
                'list' => ['type' => 'boolean', 'description' => 'List tests without executing bodies/providers.'],
            ],
            'required' => ['directory', 'files', 'test', 'list'],
            'additionalProperties' => false,
        ],
    ];
}

function agent_arguments(array $args, string $root, ?string $bootstrap): array
{
    $keys = ['directory', 'files', 'test', 'list'];
    if (array_diff(array_keys($args), $keys) !== [] || array_diff($keys, array_keys($args)) !== []) {
        throw new \InvalidArgumentException('expected exactly directory, files, test and list');
    }
    if (($args['directory'] !== null && !is_string($args['directory']))
        || !is_array($args['files']) || !is_bool($args['list'])
        || ($args['test'] !== null && (!is_string($args['test']) || $args['test'] === ''))) {
        throw new \InvalidArgumentException('invalid tool argument types');
    }
    if (($args['directory'] !== null) === ($args['files'] !== [])) {
        throw new \InvalidArgumentException('select either directory or nonempty files, not both');
    }
    $arguments = [];
    if ($args['directory'] !== null) {
        $arguments = ['-d', agent_project_path($root, $args['directory'], true)];
    } else {
        foreach ($args['files'] as $file) {
            if (!is_string($file)) { throw new \InvalidArgumentException('files must contain strings'); }
            $arguments[] = '-f';
            $arguments[] = agent_project_path($root, $file, false);
        }
    }
    if ($bootstrap !== null) { $arguments[] = '-b'; $arguments[] = $bootstrap; }
    if ($args['test'] !== null) { $arguments[] = '-t' . $args['test']; }
    if ($args['list']) { $arguments[] = '-l'; }
    return $arguments;
}

function agent_project_path(string $root, string $relative, bool $directory): string
{
    if ($relative === '' || strpos($relative, "\0") !== false
        || $relative[0] === '/' || $relative[0] === '\\' || preg_match('/^[A-Za-z]:/', $relative)) {
        throw new \InvalidArgumentException('paths must be project-relative');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($path === false || ($path !== $root && strpos($path, $prefix) !== 0)
        || !is_readable($path) || ($directory ? !is_dir($path) : !is_file($path))) {
        throw new \InvalidArgumentException('path must resolve to a readable project ' . ($directory ? 'directory' : 'file'));
    }
    return $path;
}

