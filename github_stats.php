<?php

declare(strict_types=1);

final class Queries
{
    private string $username;
    private string $accessToken;

    public function __construct(string $username, string $accessToken)
    {
        $this->username = $username;
        $this->accessToken = $accessToken;
    }

    public function query(string $generatedQuery): array
    {
        $result = $this->request(
            'https://api.github.com/graphql',
            'POST',
            ['query' => $generatedQuery]
        );

        if (isset($result['errors']) && is_array($result['errors'])) {
            $fatalMessages = [];
            foreach ($result['errors'] as $error) {
                $message = (string) ($error['message'] ?? 'Unknown error');
                // Organization-level token restrictions are partial errors: GitHub still
                // returns whatever data it could collect, so we emit a warning and continue
                // rather than aborting the whole run.
                if (str_contains($message, 'forbids access via') || str_contains($message, 'fine-grained personal access token')) {
                    fwrite(STDERR, "Warning: skipping restricted data – {$message}\n");
                } else {
                    $fatalMessages[] = $message;
                }
            }
            if ($fatalMessages !== []) {
                throw new RuntimeException('GraphQL errors: ' . implode('; ', $fatalMessages));
            }
        }

        return $result;
    }

    public function queryRest(string $path, array $params = []): array
    {
        $path = ltrim($path, '/');
        $url = 'https://api.github.com/' . $path;
        $attempt = 0;

        while ($attempt < 60) {
            $attempt++;
            try {
                return $this->request($url, 'GET', $params, true);
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'HTTP 202')) {
                    fwrite(STDERR, "A path returned 202. Retrying... (attempt {$attempt}/60)\n");
                    sleep(2);
                    continue;
                }
                if (str_contains(strtolower($message), 'rate limit')) {
                    fwrite(STDERR, "Rate limited. Retrying... (attempt {$attempt}/60)\n");
                    sleep(60);
                    continue;
                }
                if (str_contains($message, 'HTTP 404')) {
                    fwrite(STDERR, "Path {$path} not found, returning empty result\n");
                    return [];
                }
                throw $e;
            }
        }

        fwrite(STDERR, "There were too many retries. Data for this repository will be incomplete.\n");
        return [];
    }

    private function request(string $url, string $method, array $payload = [], bool $isRest = false): array
    {
        $retryableStatusCodes = [429, 500, 502, 503, 504];
        $maxAttempts = 5;
        $attempt = 0;
        $raw = '';
        $statusCode = 0;
        $lastCurlError = '';

        while ($attempt < $maxAttempts) {
            $attempt++;
            $requestUrl = $url;

            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize curl');
            }

            $headers = [
                'Authorization: Bearer ' . $this->accessToken,
                'User-Agent: gh-stats-php',
                'Accept: application/vnd.github+json',
            ];
            if ($isRest) {
                $headers[0] = 'Authorization: token ' . $this->accessToken;
            }

            if ($method === 'GET' && $payload !== []) {
                $queryString = http_build_query($payload);
                $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?') . $queryString;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $requestUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CUSTOMREQUEST => $method,
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $raw = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw !== false && !in_array($statusCode, $retryableStatusCodes, true)) {
                curl_close($ch);
                break;
            }

            $curlError = curl_error($ch);
            $lastCurlError = $curlError;
            curl_close($ch);

            if ($attempt >= $maxAttempts) {
                if ($raw === false) {
                    throw new RuntimeException('Curl request failed: ' . $curlError);
                }
                break;
            }

            $delay = min(10, (int) pow(2, $attempt));
            $reason = $raw === false ? ('curl error: ' . $curlError) : ('HTTP ' . $statusCode);
            fwrite(STDERR, "Transient API failure ({$reason}). Retrying in {$delay}s... (request attempt {$attempt} of {$maxAttempts})\n");
            sleep($delay);
        }

        if ($raw === false) {
            throw new RuntimeException('Curl request failed: ' . ($lastCurlError !== '' ? $lastCurlError : 'unknown error'));
        }

        if ($statusCode === 202) {
            throw new RuntimeException('HTTP 202');
        }
        if ($statusCode === 401) {
            throw new RuntimeException('Authentication failed. Please check your ACCESS_TOKEN.');
        }
        if ($statusCode === 403) {
            throw new RuntimeException('API rate limit exceeded or insufficient permissions.');
        }
        if ($statusCode >= 400) {
            throw new RuntimeException("GitHub API error {$statusCode}: {$raw}");
        }

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public static function reposOverview(?string $contribCursor = null, ?string $ownedCursor = null): string
    {
        $ownedCursorValue = $ownedCursor === null ? 'null' : '"' . addslashes($ownedCursor) . '"';
        $contribCursorValue = $contribCursor === null ? 'null' : '"' . addslashes($contribCursor) . '"';

        return <<<GRAPHQL
{
  viewer {
    login
    name
    repositories(
      first: 100
      orderBy: { field: UPDATED_AT, direction: DESC }
      isFork: false
      after: {$ownedCursorValue}
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        nameWithOwner
        stargazers { totalCount }
        forkCount
        languages(first: 10, orderBy: { field: SIZE, direction: DESC }) {
          edges {
            size
            node { name color }
          }
        }
      }
    }
    repositoriesContributedTo(
      first: 100
      includeUserRepositories: false
      orderBy: { field: UPDATED_AT, direction: DESC }
      contributionTypes: [COMMIT, PULL_REQUEST, REPOSITORY, PULL_REQUEST_REVIEW]
      after: {$contribCursorValue}
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        nameWithOwner
        stargazers { totalCount }
        forkCount
        languages(first: 10, orderBy: { field: SIZE, direction: DESC }) {
          edges {
            size
            node { name color }
          }
        }
      }
    }
  }
}
GRAPHQL;
    }

    public static function contribYears(): string
    {
        return <<<'GRAPHQL'
query {
  viewer {
    contributionsCollection {
      contributionYears
    }
  }
}
GRAPHQL;
    }

    public static function allContribs(array $years): string
    {
        $parts = [];
        foreach ($years as $year) {
            $yearValue = (string) $year;
            $yearNext = (string) ((int) $yearValue + 1);
            $parts[] = <<<GRAPHQL
    year{$yearValue}: contributionsCollection(
      from: "{$yearValue}-01-01T00:00:00Z",
      to: "{$yearNext}-01-01T00:00:00Z"
    ) {
      contributionCalendar { totalContributions }
    }
GRAPHQL;
        }

        $joined = implode("\n", $parts);
        return <<<GRAPHQL
query {
  viewer {
{$joined}
  }
}
GRAPHQL;
    }
}

final class Stats
{
    private string $username;
    private array $excludeRepos;
    private array $excludeLangs;
    private bool $considerForkedRepos;
    private Queries $queries;

    private ?string $name = null;
    private ?int $stargazers = null;
    private ?int $forks = null;
    private ?int $totalContributions = null;
    private ?array $languages = null;
    private ?array $repos = null;
    private ?array $ignoredRepos = null;
    private ?array $linesChanged = null;
    private ?int $views = null;

    public function __construct(
        string $username,
        string $accessToken,
        array $excludeRepos = [],
        array $excludeLangs = [],
        bool $considerForkedRepos = false
    ) {
        $this->username = $username;
        $this->excludeRepos = array_fill_keys($excludeRepos, true);
        $this->excludeLangs = array_fill_keys($excludeLangs, true);
        $this->considerForkedRepos = $considerForkedRepos;
        $this->queries = new Queries($username, $accessToken);
    }

    public function getName(): string
    {
        if ($this->name === null) {
            $this->getStats();
        }
        return $this->name ?? 'No Name';
    }

    public function getStargazers(): int
    {
        if ($this->stargazers === null) {
            $this->getStats();
        }
        return $this->stargazers ?? 0;
    }

    public function getForks(): int
    {
        if ($this->forks === null) {
            $this->getStats();
        }
        return $this->forks ?? 0;
    }

    public function getLanguages(): array
    {
        if ($this->languages === null) {
            $this->getStats();
        }
        return $this->languages ?? [];
    }

    public function getRepos(): array
    {
        if ($this->repos === null) {
            $this->getStats();
        }
        return array_keys($this->repos ?? []);
    }

    public function getAllRepos(): array
    {
        if ($this->repos === null || $this->ignoredRepos === null) {
            $this->getStats();
        }
        return array_keys(array_merge($this->repos ?? [], $this->ignoredRepos ?? []));
    }

    public function getTotalContributions(): int
    {
        if ($this->totalContributions !== null) {
            return $this->totalContributions;
        }

        $this->totalContributions = 0;
        $years = $this->queries->query(Queries::contribYears())['data']['viewer']['contributionsCollection']['contributionYears'] ?? [];
        if (!is_array($years) || $years === []) {
            return 0;
        }

        $byYear = $this->queries->query(Queries::allContribs($years))['data']['viewer'] ?? [];
        if (!is_array($byYear)) {
            return 0;
        }

        foreach ($byYear as $yearData) {
            if (!is_array($yearData)) {
                continue;
            }
            $this->totalContributions += (int) ($yearData['contributionCalendar']['totalContributions'] ?? 0);
        }

        return $this->totalContributions;
    }

    public function getLinesChanged(): array
    {
        if ($this->linesChanged !== null) {
            return $this->linesChanged;
        }

        $additions = 0;
        $deletions = 0;
        foreach ($this->getAllRepos() as $repo) {
            $contributors = $this->queries->queryRest("/repos/{$repo}/stats/contributors");
            if (!is_array($contributors)) {
                continue;
            }
            foreach ($contributors as $authorObj) {
                if (!is_array($authorObj)) {
                    continue;
                }
                $author = $authorObj['author']['login'] ?? '';
                if ($author !== $this->username) {
                    continue;
                }
                $weeks = $authorObj['weeks'] ?? [];
                if (!is_array($weeks)) {
                    continue;
                }
                foreach ($weeks as $week) {
                    if (!is_array($week)) {
                        continue;
                    }
                    $additions += (int) ($week['a'] ?? 0);
                    $deletions += (int) ($week['d'] ?? 0);
                }
            }
        }

        $this->linesChanged = [$additions, $deletions];
        return $this->linesChanged;
    }

    public function getViews(): int
    {
        if ($this->views !== null) {
            return $this->views;
        }

        $total = 0;
        foreach ($this->getRepos() as $repo) {
            $views = $this->queries->queryRest("/repos/{$repo}/traffic/views");
            $viewItems = $views['views'] ?? [];
            if (!is_array($viewItems)) {
                continue;
            }
            foreach ($viewItems as $view) {
                if (!is_array($view)) {
                    continue;
                }
                $total += (int) ($view['count'] ?? 0);
            }
        }

        $this->views = $total;
        return $total;
    }

    private function getStats(): void
    {
        if ($this->stargazers !== null && $this->forks !== null && $this->languages !== null && $this->repos !== null) {
            return;
        }

        $this->stargazers = 0;
        $this->forks = 0;
        $this->languages = [];
        $this->repos = [];
        $this->ignoredRepos = [];

        $nextOwned = null;
        $nextContrib = null;

        while (true) {
            $rawResults = $this->queries->query(Queries::reposOverview($nextContrib, $nextOwned));
            $viewer = $rawResults['data']['viewer'] ?? [];
            if (!is_array($viewer)) {
                $viewer = [];
            }

            $this->name = (string) ($viewer['name'] ?? $viewer['login'] ?? 'No Name');

            $contribRepos = $viewer['repositoriesContributedTo'] ?? [];
            $ownedRepos = $viewer['repositories'] ?? [];
            $repos = is_array($ownedRepos['nodes'] ?? null) ? $ownedRepos['nodes'] : [];

            if ($this->considerForkedRepos) {
                $contribNodes = is_array($contribRepos['nodes'] ?? null) ? $contribRepos['nodes'] : [];
                $repos = array_merge($repos, $contribNodes);
            } else {
                $contribNodes = is_array($contribRepos['nodes'] ?? null) ? $contribRepos['nodes'] : [];
                foreach ($contribNodes as $repo) {
                    if (!is_array($repo)) {
                        continue;
                    }
                    $name = (string) ($repo['nameWithOwner'] ?? '');
                    if ($name === '' || isset($this->ignoredRepos[$name]) || isset($this->excludeRepos[$name])) {
                        continue;
                    }
                    $this->ignoredRepos[$name] = true;
                }
            }

            foreach ($repos as $repo) {
                if (!is_array($repo)) {
                    continue;
                }

                $nameWithOwner = (string) ($repo['nameWithOwner'] ?? '');
                if ($nameWithOwner === '' || isset($this->repos[$nameWithOwner]) || isset($this->excludeRepos[$nameWithOwner])) {
                    continue;
                }

                $this->repos[$nameWithOwner] = true;
                $this->stargazers += (int) ($repo['stargazers']['totalCount'] ?? 0);
                $this->forks += (int) ($repo['forkCount'] ?? 0);

                $languageEdges = $repo['languages']['edges'] ?? [];
                if (!is_array($languageEdges)) {
                    continue;
                }
                foreach ($languageEdges as $lang) {
                    if (!is_array($lang)) {
                        continue;
                    }

                    $langName = (string) ($lang['node']['name'] ?? 'Other');
                    if (isset($this->excludeLangs[$langName])) {
                        continue;
                    }

                    if (!isset($this->languages[$langName])) {
                        $this->languages[$langName] = [
                            'size' => 0,
                            'occurrences' => 0,
                            'color' => $lang['node']['color'] ?? null,
                            'prop' => 0.0,
                        ];
                    }

                    $this->languages[$langName]['size'] += (int) ($lang['size'] ?? 0);
                    $this->languages[$langName]['occurrences'] += 1;
                    if (($this->languages[$langName]['color'] ?? null) === null && isset($lang['node']['color'])) {
                        $this->languages[$langName]['color'] = $lang['node']['color'];
                    }
                }
            }

            $ownedPageInfo = $ownedRepos['pageInfo'] ?? [];
            $contribPageInfo = $contribRepos['pageInfo'] ?? [];
            $ownedNext = (bool) ($ownedPageInfo['hasNextPage'] ?? false);
            $contribNext = (bool) ($contribPageInfo['hasNextPage'] ?? false);
            if (!$ownedNext && !$contribNext) {
                break;
            }

            $nextOwned = $ownedPageInfo['endCursor'] ?? $nextOwned;
            $nextContrib = $contribPageInfo['endCursor'] ?? $nextContrib;
        }

        $totalLanguageSize = 0;
        foreach ($this->languages as $language) {
            $totalLanguageSize += (int) ($language['size'] ?? 0);
        }
        if ($totalLanguageSize > 0) {
            foreach ($this->languages as $name => $language) {
                $size = (int) ($language['size'] ?? 0);
                $this->languages[$name]['prop'] = 100.0 * $size / $totalLanguageSize;
            }
        }
    }
}
