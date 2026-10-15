<?php

declare(strict_types=1);

require_once __DIR__ . '/github_stats.php';

function generateOutputFolder(): void
{
    if (!is_dir(__DIR__ . '/generated')) {
        mkdir(__DIR__ . '/generated', 0775, true);
        echo "Created generated/ directory\n";
    }
}

/**
 * Print a GitHub Actions error annotation so the failure reason is visible
 * directly on the workflow run summary without digging through the logs.
 */
function annotateError(string $message): void
{
    $clean = str_replace(["\r", "\n"], ' ', $message);
    $clean = str_replace('%', '%25', $clean);
    echo "::error::{$clean}\n";
}

/**
 * Print a GitHub Actions warning annotation.
 */
function annotateWarning(string $message): void
{
    $clean = str_replace(["\r", "\n"], ' ', $message);
    $clean = str_replace('%', '%25', $clean);
    echo "::warning::{$clean}\n";
}

function validateTemplates(): void
{
    $requiredTemplates = [
        __DIR__ . '/templates/overview.svg' => ['{{ name }}', '{{ stars }}', '{{ forks }}', '{{ contributions }}', '{{ lines_changed }}', '{{ views }}', '{{ repos }}'],
        __DIR__ . '/templates/languages.svg' => ['{{ progress }}', '{{ lang_list }}'],
    ];

    foreach ($requiredTemplates as $templatePath => $requiredPlaceholders) {
        if (!is_file($templatePath)) {
            throw new RuntimeException("Template file {$templatePath} not found!");
        }

        $content = (string) file_get_contents($templatePath);
        foreach ($requiredPlaceholders as $placeholder) {
            if (!str_contains($content, $placeholder)) {
                throw new RuntimeException("Template {$templatePath} is missing placeholder: {$placeholder}");
            }
        }
    }

    echo "All template files validated successfully!\n";
}

/**
 * Pick a working GitHub token.
 *
 * Order of preference:
 *   1. ACCESS_TOKEN (personal access token, full stats including private
 *      repositories and traffic views)
 *   2. GITHUB_TOKEN (the repository's built-in Actions token, public stats
 *      only - used as a graceful fallback so the workflow still produces
 *      images when the PAT is missing, invalid, or expired)
 *
 * @return array{0: string, 1: string} The token to use and which candidate it came from.
 */
function resolveToken(): array
{
    $accessToken = trim((string) getenv('ACCESS_TOKEN'));
    $githubToken = trim((string) getenv('GITHUB_TOKEN'));

    $candidates = [];
    // When the ACCESS_TOKEN secret is not set, the workflow maps ACCESS_TOKEN
    // to github.token. In that case both values are identical and we should
    // treat it purely as the Actions token.
    if ($accessToken !== '' && $accessToken !== $githubToken) {
        $candidates['ACCESS_TOKEN'] = $accessToken;
    }
    if ($githubToken !== '') {
        $candidates['GITHUB_TOKEN'] = $githubToken;
    }

    if ($candidates === []) {
        throw new RuntimeException(
            'No GitHub token found. Set the ACCESS_TOKEN secret to a personal access token '
            . 'with the repo and read:user scopes (see the README installation steps).'
        );
    }

    foreach ($candidates as $name => $token) {
        $probe = Queries::probeToken($token);
        if ($probe !== null) {
            $login = (string) ($probe['login'] ?? 'unknown');
            echo "Using {$name} (authenticated as {$login}).\n";
            if ($name === 'GITHUB_TOKEN') {
                annotateWarning(
                    'Falling back to GITHUB_TOKEN: stats are limited to public data. '
                    . 'For full stats (private repos + traffic views) set a valid ACCESS_TOKEN secret '
                    . 'with a personal access token (scopes: repo, read:user).'
                );
            }
            return [$token, $name];
        }

        if ($name === 'ACCESS_TOKEN') {
            annotateWarning(
                'ACCESS_TOKEN is set but invalid or expired. Regenerate a personal access token '
                . '(scopes: repo, read:user) and update the ACCESS_TOKEN secret. '
                . 'Falling back to GITHUB_TOKEN (public stats only) for this run.'
            );
            fwrite(STDERR, "Warning: ACCESS_TOKEN is invalid or expired. Trying GITHUB_TOKEN as fallback.\n");
        }
    }

    throw new RuntimeException(
        'No usable GitHub token found. ACCESS_TOKEN is invalid or expired: regenerate a personal '
        . 'access token (scopes: repo, read:user) and update the ACCESS_TOKEN secret.'
    );
}

function generateOverview(Stats $stats): void
{
    echo "Generating overview SVG...\n";
    $templatePath = __DIR__ . '/templates/overview.svg';
    if (!is_file($templatePath)) {
        throw new RuntimeException('Template file templates/overview.svg not found!');
    }

    $output = (string) file_get_contents($templatePath);
    $name = $stats->getName();
    $stargazers = $stats->getStargazers();
    $forks = $stats->getForks();
    $contributions = $stats->getTotalContributions();
    [$added, $deleted] = $stats->getLinesChanged();
    $views = $stats->getViews();
    $repos = $stats->getAllRepos();

    echo sprintf(
        "Stats collected - Name: %s, Stars: %s, Forks: %s, Contributions: %s\n",
        $name,
        number_format($stargazers),
        number_format($forks),
        number_format($contributions)
    );

    $replaceMap = [
        '{{ name }}' => $name,
        '{{ stars }}' => number_format($stargazers),
        '{{ forks }}' => number_format($forks),
        '{{ contributions }}' => number_format($contributions),
        '{{ lines_changed }}' => number_format($added + $deleted),
        '{{ views }}' => number_format($views),
        '{{ repos }}' => number_format(count($repos)),
    ];

    $output = str_replace(array_keys($replaceMap), array_values($replaceMap), $output);
    generateOutputFolder();

    $outputPath = __DIR__ . '/generated/overview.svg';
    file_put_contents($outputPath, $output);

    if (!is_file($outputPath)) {
        throw new RuntimeException('Failed to create overview.svg file');
    }
    $fileSize = filesize($outputPath);
    if ($fileSize === false || $fileSize < 100) {
        throw new RuntimeException('Generated overview.svg seems too small');
    }

    echo "Overview SVG generated successfully! ({$fileSize} bytes)\n";
}

function generateLanguages(Stats $stats): void
{
    echo "Generating languages SVG...\n";
    $templatePath = __DIR__ . '/templates/languages.svg';
    if (!is_file($templatePath)) {
        throw new RuntimeException('Template file templates/languages.svg not found!');
    }

    $output = (string) file_get_contents($templatePath);
    $languages = $stats->getLanguages();
    if ($languages === []) {
        echo "Warning: No languages found in repositories\n";
        $languages = [
            'Unknown' => [
                'size' => 1,
                'color' => '#cccccc',
                'prop' => 100.0,
            ],
        ];
    }

    uasort(
        $languages,
        static fn(array $a, array $b): int => ((int) ($b['size'] ?? 0)) <=> ((int) ($a['size'] ?? 0))
    );

    echo 'Found ' . count($languages) . " languages\n";

    $progress = '';
    $langList = '';
    $delayBetween = 150;
    $index = 0;
    $lastIndex = count($languages) - 1;
    foreach ($languages as $lang => $data) {
        $color = (string) ($data['color'] ?? '#000000');
        if ($color === '') {
            $color = '#000000';
        }
        $prop = (float) ($data['prop'] ?? 0.0);
        $ratio = [0.98, 0.02];
        if ($prop > 50) {
            $ratio = [0.99, 0.01];
        }
        if ($index === $lastIndex) {
            $ratio = [1.0, 0.0];
        }
        $progress .= sprintf(
            '<span style="background-color: %s;width: %.3f%%;margin-right: %.3f%%;" class="progress-item"></span>',
            $color,
            $ratio[0] * $prop,
            $ratio[1] * $prop
        );

        $langList .= sprintf(
            "\n<li style=\"animation-delay: %dms;\">\n<svg xmlns=\"http://www.w3.org/2000/svg\" class=\"octicon\" style=\"fill:%s;\" viewBox=\"0 0 16 16\" version=\"1.1\" width=\"16\" height=\"16\"><path fill-rule=\"evenodd\" d=\"M8 4a4 4 0 100 8 4 4 0 000-8z\"></path></svg>\n<span class=\"lang\">%s</span>\n<span class=\"percent\">%.2f%%</span>\n</li>\n",
            $index * $delayBetween,
            $color,
            htmlspecialchars((string) $lang, ENT_QUOTES),
            $prop
        );

        $index++;
    }

    $output = str_replace(
        ['{{ progress }}', '{{ lang_list }}'],
        [$progress, $langList],
        $output
    );

    generateOutputFolder();
    $outputPath = __DIR__ . '/generated/languages.svg';
    file_put_contents($outputPath, $output);

    if (!is_file($outputPath)) {
        throw new RuntimeException('Failed to create languages.svg file');
    }
    $fileSize = filesize($outputPath);
    if ($fileSize === false || $fileSize < 100) {
        throw new RuntimeException('Generated languages.svg seems too small');
    }

    echo "Languages SVG generated successfully! ({$fileSize} bytes)\n";
}

function parseSetFromEnv(string $key): array
{
    $value = trim((string) getenv($key));
    if ($value === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts, static fn(string $item): bool => $item !== ''));
}

function envEnabled(string $key): bool
{
    $value = strtolower(trim((string) getenv($key)));
    if ($value === '') {
        return false;
    }
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function main(): int
{
    try {
        echo "Starting GitHub Stats generation...\n";
        validateTemplates();

        [$accessToken, $tokenSource] = resolveToken();

        $user = trim((string) getenv('GITHUB_ACTOR'));
        if ($user === '') {
            throw new RuntimeException('GITHUB_ACTOR environment variable is required!');
        }

        $excludeRepos = parseSetFromEnv('EXCLUDED');
        $excludeLangs = parseSetFromEnv('EXCLUDED_LANGS');
        $considerForkedRepos = envEnabled('COUNT_STATS_FROM_FORKS');

        echo "Configuration:\n";
        echo "  User: {$user}\n";
        echo "  Token source: {$tokenSource}\n";
        echo '  Consider forked repositories: ' . ($considerForkedRepos ? 'True' : 'False') . "\n";
        if ($excludeRepos !== []) {
            echo '  Excluded repositories: ' . implode(', ', $excludeRepos) . "\n";
        }
        if ($excludeLangs !== []) {
            echo '  Excluded languages: ' . implode(', ', $excludeLangs) . "\n";
        }

        $stats = new Stats($user, $accessToken, $excludeRepos, $excludeLangs, $considerForkedRepos);

        generateLanguages($stats);
        generateOverview($stats);
        echo "Successfully generated all stats images!\n";
        return 0;
    } catch (Throwable $e) {
        $message = 'Error generating stats: ' . $e->getMessage();
        annotateError($message);
        fwrite(STDERR, $message . "\n");
        return 1;
    }
}

exit(main());
