<?php

const QUICK_MERGE_THRESHOLD_SECONDS = 259200; // 3 days
const OUTLIER_MERGE_THRESHOLD_SECONDS = 1814400; // 21 days
const FEW_RESCOPES_THRESHOLD = 2;
const STYLE_HIGHLIGHT = 'background-color:#ffa';

const STAT_PRS_CREATED_THRESHOLD = 5;
const STAT_PRS_REVIEWED_THRESHOLD = 10;
const STAT_PRS_APPROVED_THRESHOLD = 10;
const STAT_COMMENTS_THRESHOLD = 50;

$CONFIG = parse_ini_file('./config.ini');

function main()
{
    $errorHtml = '';
    $summaryHtml = 'N/A';
    $scoreBoardHtml = 'N/A';
    $pullRequestStatsHtml = 'N/A';
    $memberStatsHtml = 'N/A';
    $linksHtml = 'N/A';

    try {
        $metrics = getMetrics();
        if (empty($metrics) === false) {
            $summaryHtml = makeSummaryHtml($metrics);
            $pullRequestStatsHtml = makePullRequestStatsHtml($metrics);
            [$scoreBoardHtml, $memberStatsHtml] = makeMetricsHtml($metrics);
        }
        $linksHtml = makeLinksHtml();
    } catch (Exception $e) {
        $errorHtml = $e->getMessage();
    }

    echo <<<"END_HTML"
        <!DOCTYPE html>
        <html>
        <head>
        <title>Code Review Metrics</title>
        <style>
        table, th, td {
          border: 1px solid black;
          border-collapse: collapse;
          min-width: 50px;
        }
        .scoreboard-grid {
          display: flex;
          width: 100%;
          justify-content: space-evenly;
        }
        .profile-pic {
          border-radius: 50%;
          width: 64px;
          height: 64px;
        }
        </style>
        </head>
        <body>
          <h1>Code Review Metrics</h1>
          $errorHtml
          <h2>Summary</h2>
          $summaryHtml
          <h2>Merged Pull Request Stats</h2>
          $pullRequestStatsHtml
          <h2>Score Board</h2>
          $scoreBoardHtml
          <h2>Individual Stats</h2>
          $memberStatsHtml
          <h2>Available Metrics</h2>
          $linksHtml
          <h2>About</h2>
          <ul>
            <li>This tracks code review activity for each week across several repositories.</li>
            <li>Comments and review on one's own PR are not counted.</li>
            <li>Comment replies are not counted.</li>
            <li>Approvals count even if they're lost when the PR is updated.</li>
            <li>Activity is not counted for PRs which have not been updated for &gt; 2 weeks.</li>
          </ul>
        </body>
        </html>
END_HTML;
}

function getMetrics(): ?array
{
    global $CONFIG;

    $dateParam = $_GET['date'] ?? 'today';
    $date = date_create($dateParam);
    if ($date instanceof DateTime === false) {
        throw new Exception("<h2>Error</h2>Invalid date: $dateParam");
    }

    $filepath = sprintf($CONFIG['filepath_format'], $date->format('Y-m-d'));

    $metrics = null;
    if (file_exists($filepath)) {
        $f = fopen($filepath, 'r');
        $metrics = json_decode(fread($f, filesize($filepath)), true);
    } else {
      throw new Exception('<h2>Error</h2>No metrics for ' . $date->format('Y-m-d'));
    }

    return $metrics;
}

function makeSummaryHtml(array $metrics): string
{
    return <<<"END_HTML"
        <p>Target pull requests updated after {$metrics['pull_request_target_start_date']}</p>
        <p>Target activities after {$metrics['activity_target_start_date']}</p>
        <p>Metrics recorded at {$metrics['recorded_at']}</p>
        <table>
          <tbody>
            <tr><th>Total PRs Created</th><td>{$metrics['num_new_prs']}</td></tr>
            <tr><th>Total PRs Merged</th><td>{$metrics['num_merged_prs']}</td></tr>
          </tbody>
        </table>
END_HTML;
}

function makeUserAvatarHtml(string $name): string
{
    global $CONFIG;

    if (file_exists($name . '.png') === false) {
        $avatarUrl = sprintf($CONFIG['avatar_image_url_format'], $name);
        shell_exec("wget $avatarUrl -O $name.png");
    }
    return "<img class=\"profile-pic\" src=\"/{$CONFIG['public_image_directory']}$name.png\"/>";
}

function formatHumanReadableTime(int $seconds): string
{
    if ($seconds / 60 < 1) {
      return round($seconds, 2) . ' seconds';
    }
    $minutes = $seconds / 60;
    if ($minutes / 60 < 1) {
        return round($minutes, 2) . ' minutes';
    }
    $hours = $minutes / 60;
    if ($hours / 24 < 1) {
        return round($hours, 2) . ' hours';
    }
    $days = $hours / 24;
    return round($days, 2) . ' days';
}

function makePullRequestStatsHtml(array $metrics): string
{
    $times = [];
    $rows = [];
    foreach ($metrics['merged_pr_info'] as $info) {
        $avatarHtml = makeUserAvatarHtml($info['author']);
        if ($info['time_to_merge_s'] <= OUTLIER_MERGE_THRESHOLD_SECONDS) {
            $times[] = $info['time_to_merge_s'];
        }
        $humanReadableTime = formatHumanReadableTime($info['time_to_merge_s']);
        $ttmStyle = $info['time_to_merge_s'] <= QUICK_MERGE_THRESHOLD_SECONDS ? STYLE_HIGHLIGHT : '';
        $rescopeStyle = $info['num_rescopes'] <= FEW_RESCOPES_THRESHOLD ? STYLE_HIGHLIGHT : '';
        $rows[] = <<<"END_HTML"
            <tr>
              <td>{$info['repo']}</th>
              <td>{$info['id']}</th>
              <td>$avatarHtml</th>
              <td>{$info['author']}</th>
              <td style="{$ttmStyle}">$humanReadableTime</th>
              <td>{$info['from_ref']}</th>
              <td>{$info['to_ref']}</th>
              <td>{$info['num_comments']}</th>
              <td style="{$rescopeStyle}">{$info['num_rescopes']}</th>
              <td>{$info['num_files_changed']}</th>
              <td>{$info['num_files_added']}</th>
              <td>{$info['num_files_modified']}</th>
              <td>{$info['num_files_deleted']}</th>
            </tr>
END_HTML;
    }
    $rowHtml = implode('', $rows);

    $average = '';
    if (count($times) > 0) {
        $average = formatHumanReadableTime((int) (array_sum($times) / count($times)));
    }

    $outlierThresholdDays = OUTLIER_MERGE_THRESHOLD_SECONDS / (60*60*24);
    return <<<"END_HTML"
        <p>Average time to merge: $average</p>
        <p>※ Excludes outliers over $outlierThresholdDays days</p>
        <table>
          <thead>
            <th>Repo</th>
            <th>ID</th>
            <th colspan="2">Author</th>
            <th>Time To Merge</th>
            <th>From</th>
            <th>To</th>
            <th>Num Comments</th>
            <th>Num Rescopes</th>
            <th>Num Files Changed</th>
            <th>Num Files Added</th>
            <th>Num Files Modified</th>
            <th>Num Files Deleted</th>
          </thead>
          <tbody>$rowHtml</tbody>
        </table>
END_HTML;
}

function makeMetricsHtml(array $metrics): array
{
    $altruism = [];
    $communication = [];
    $vigilance = [];
    $contribution = [];

    $memberStatsHtmls = [];
    foreach ($metrics['member_info'] as $name => $info) {
        $altruism[] = [
            'name' => $name,
            'score' => $info['num_prs_approved'] - $info['num_prs_created'],
        ];
        $communication[] = [
            'name' => $name,
            'score' => $info['num_comments'],
        ];
        if ($metrics['num_new_prs'] > 0 && $metrics['num_new_prs'] !== $info['num_prs_created']) {
            $vigilance[] = [
                'name' => $name,
                'score' => round($info['num_prs_reviewed'] / ($metrics['num_new_prs'] - $info['num_prs_created']), 2),
            ];
        }
        if ($metrics['num_merged_prs'] > 0) {
            $contribution[] = [
                'name' => $name,
                'score' => round($info['num_prs_approved'] / $metrics['num_merged_prs'], 2),
            ];
        }

        $avatarHtml = makeUserAvatarHtml($name);
        $radarChartHtml = makeRadarChartHtml($info);
        $memberStatsHtmls[] = <<<"END_HTML"
            <table>
            <thead>
            <th colspan="4">$name</th>
            </thead>
            <tbody>
            <tr>
              <td rowspan="0">$avatarHtml</td>
              <td>PRs Created</td><td>{$info['num_prs_created']}</td>
              <td rowspan="0">$radarChartHtml</td>
            </tr>
            <tr><td>PRs Reviewed</td><td>{$info['num_prs_reviewed']}</td><tr>
            <tr><td>PRs Approved</td><td>{$info['num_prs_approved']}</td><tr>
            <tr><td>Comments</td><td>{$info['num_comments']}</td><tr>
            </tbody>
            </table>
END_HTML;
    }

    $altruismHtml = makeScoreBoardHtml($altruism);
    $communicationHtml = makeScoreBoardHtml($communication);
    $vigilanceHtml = makeScoreBoardHtml($vigilance, true);
    $contributionHtml = makeScoreBoardHtml($contribution);
    $scoreBoardHtml = <<<"END_HTML"
        <div class="scoreboard-grid">
            <div>
                <h3>Altruism</h3>
                <p>(Approvals - Creations)</p>
                $altruismHtml
            </div>
            <div>
                <h3>Communication</h3>
                <p>(Num Comments)</p>
                $communicationHtml
            </div>
            <div>
                <h3>Vigilance</h3>
                <p>(Reviews / Total PR Creations)</p>
                $vigilanceHtml
            </div>
            <div>
                <h3>Coup de Grâce</h3>
                <p>(Approvals / Total PR Merges)</p>
                $contributionHtml
            </div>
        </div>
END_HTML;

    $memberStatsHtml = implode('', $memberStatsHtmls);

    return [$scoreBoardHtml, $memberStatsHtml];
}

function makeScoreBoardHtml(array $members, bool $showPercentage = false): string
{
    usort($members, function (array $a, array $b) { return $b['score'] <=> $a['score']; });
    $rows = [];
    $rank = 1;
    $prevScore = null;
    foreach ($members as $member) {
        $avatarHtml = makeUserAvatarHtml($member['name']);
        if ($prevScore !== null && $member['score'] < $prevScore) {
            $rank += 1;
        }
        $percentageHtml = '';
        if ($showPercentage) {
            $percentageHtml = makePercentageBarHtml($member['score']);
        }
        $rows[] = "<tr><td>$rank</td><td>$avatarHtml</td><td>{$member['name']}</td><td>{$member['score']}$percentageHtml</td></tr>";
        $prevScore = $member['score'];
    }
    $rowHtml = implode('', $rows);
    return "<table width=\"100%\"><thead><th>Rank</th><th colspan=\"3\">Member</th></thead><tbody>$rowHtml</tbody></table>";
}

function makePercentageBarHtml(float $score): string
{
    $percent = 100 - min(100, ((int) (round($score, 2) * 100)));
    return <<<"END_HTML"
        <br>
        <div style="width:300px;height:24px;background:linear-gradient(to right,#F63,#3F3)">
            <div style="float:right;height:100%;width:$percent%;background:#CCC;"></div>
        </div>
END_HTML;
}

function makeRadarChartHtml(array $info): string
{
    $backdrop  = makeFourPointRadar(1, 1, 1, 1);
    $infoRadar = makeFourPointRadar(
        $info['num_prs_created'] / STAT_PRS_CREATED_THRESHOLD,
        $info['num_prs_reviewed'] / STAT_PRS_REVIEWED_THRESHOLD,
        $info['num_prs_approved'] / STAT_PRS_APPROVED_THRESHOLD,
        $info['num_comments'] / STAT_COMMENTS_THRESHOLD
    );
    return <<<"END_HTML"
        <div style="background:#CCC;clip-path:$backdrop;width:100px;height:100px;">
            <div style="background:radial-gradient(#F60,#3F0,#0F0);clip-path:$infoRadar;width:100px;height:100px;"></div>
        </div>
END_HTML;
}

/**
 * Base is from 10% (5~50) for visual clarity.
 */
function makeFourPointRadar(float $topScore, float $rightScore, float $bottomScore, float $leftScore): string
{
    $top    = 48 - round(48 * $topScore);
    $right  = 52 + round(48 * $rightScore);
    $bottom = 52 + round(48 * $bottomScore);
    $left   = 48 - round(48 * $leftScore);
    return "polygon(50px {$top}px, {$right}px 50px, 50px {$bottom}px, {$left}px 50px)";
}

function makeLinksHtml(): string
{
    global $CONFIG;

    $files = explode("\n", shell_exec('ls ' . sprintf($CONFIG['filepath_format'], '*')));
    $links = [];
    $name = $argv[0] ?? '';
    foreach ($files as $file) {
        if (preg_match('/metrics_(\d{4}-\d{2}-\d{2}).json/', $file, $matches) === 1) {
            $links[] = "<li><a href=\"{$name}?date={$matches[1]}\">{$matches[1]}</a></li>";
        }
    }
    return '<ul>' . implode('', $links) . '</ul>';
}

main();
