<?php

declare(strict_types=1);

/*
 * This file is part of the HubKit package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace HubKit\Helper;

use HubKit\Service\Git;
use HubKit\Service\GitHub;
use HubKit\StringUtil;

class ChangelogRenderer
{
    private $git;
    private $github;

    public function __construct(Git $git, GitHub $github)
    {
        $this->git = $git;
        $this->github = $github;
    }

    public function renderChangelogOneLine(string $base, string $head): string
    {
        $url = 'https://'.$this->github->getHostname().'/'.$this->github->getOrganization().'/'.$this->github->getRepository();
        $changelog = '';

        foreach ($this->git->getLogBetweenCommits($base, $head) as $commit) {
            if ($this->extractInfoFromSubject($commit['subject'], $matches)) {
                $labels = $this->getLabels($commit['message']);
                $changelog .= $this->formatLine($matches + ['labels' => $labels], $url);
            }
        }

        return trim($changelog);
    }

    public function renderChangelogByCategories(string $base, string $head, bool $skipEmptyLists = true): string
    {
        $url = 'https://'.$this->github->getHostname().'/'.$this->github->getOrganization().'/'.$this->github->getRepository();
        $changelog = '';

        foreach ($this->getItemsPerCategories($base, $head) as $category => $items) {
            if (!count($items)) {
                if (!$skipEmptyLists) {
                    $changelog .= "### {$category}\n- nothing\n\n";
                }

                continue;
            }

            $changelog .= "### {$category}\n";

            foreach ($items as $item) {
                $changelog .= $this->formatLine($item, $url);
            }

            $changelog .= "\n";
        }

        return trim($changelog);
    }

    private function extractInfoFromSubject(string $subject, &$matches): bool
    {
        return 0 !== stripos($subject, 'Merge pull request #') &&
               preg_match('/^(?P<category>\w+) #(?P<number>\d+) (?P<title>.+?)$/', $subject, $matches);
    }

    private function formatLine(array $item, string $url): string
    {
        $title = $item['title'];
        $pos = mb_strrpos($title, '(');

        // Replace authors with links
        $title = mb_substr($title, 0, $pos).
            preg_replace(
                '#([\w\d\-_]+)#',
                '[@$1](https://'.$this->github->getHostname().'/$1)',
                mb_substr($title, $pos)
            )
        ;

        if (in_array('bc-break', $item['labels'], true)) {
            $title = '[BC BREAK] '.$title;
        }

        return sprintf('- [#%d](%s/issues/%1$d) %s', $item['number'], $url, trim($title))."\n";
    }

    private function getItemsPerCategories(string $base, string $head): array
    {
        $categories = [
            'Security' => [],
            'Added' => [],
            'Changed' => [],
            'Deprecated' => [],
            'Removed' => [],
            'Fixed' => [],
        ];

        foreach ($this->git->getLogBetweenCommits($base, $head) as $commit) {
            if (!$this->extractInfoFromSubject($commit['subject'], $matches)) {
                continue;
            }

            $labels = $this->getLabels($commit['message']);
            $category = $this->getCategoryForCommit($commit + $matches, $labels);
            $categories[$category][] = $matches + ['labels' => $labels];
        }

        return $categories;
    }

    private function getCategoryForCommit(array $commit, array $labels): string
    {
        // Security can only ever be related about security.
        if ('security' === $commit['category']) {
            return 'Security';
        }

        $catToFinal = [
            'feature' => 'Added',
            'refactor' => 'Changed',
            'bug' => 'Fixed',
        ];

        if (in_array('deprecation', $labels, true)) {
            return 'Deprecated';
        }

        if (in_array('removed-deprecation', $labels, true)) {
            return 'Removed';
        }

        return $catToFinal[$commit['category']] ?? 'Changed';
    }

    private function getLabels(string $message): array
    {
        list(, $labelsStr) = StringUtil::splitLines(ltrim($message));

        // Detect labels eg. `labels: deprecation`
        if (0 === strpos($labelsStr, 'labels: ')) {
            return preg_split('/\s*,\s*/', substr($labelsStr, 8));
        }

        return [];
    }
}
