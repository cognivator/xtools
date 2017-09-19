<?php
/**
 * This file contains only the EditCounter class.
 */

namespace Xtools;

use DateTime;
use Exception;
use DatePeriod;
use DateInterval;
use GuzzleHttp;
use GuzzleHttp\Promise\Promise;

/**
 * An EditCounter provides statistics about a user's edits on a project.
 */
class EditCounter extends Model
{

    /** @var Project The project. */
    protected $project;

    /** @var User The user. */
    protected $user;

    /** @var int[] Revision and page counts etc. */
    protected $pairData;

    /** @var string[] The start and end dates of revisions. */
    protected $revisionDates;

    /** @var int[] The total page counts. */
    protected $pageCounts;

    /** @var int[] The lot totals. */
    protected $logCounts;

    /** @var mixed[] Total numbers of edits per month */
    protected $monthCounts;

    /** @var mixed[] Total numbers of edits per year */
    protected $yearCounts;

    /** @var int[] Keys are project DB names. */
    protected $globalEditCounts;

    /** @var array Block data, with keys 'set' and 'received'. */
    protected $blocks;

    /** @var integer[] Array keys are namespace IDs, values are the edit counts */
    protected $namespaceTotals;

    /** @var int Number of semi-automated edits */
    protected $autoEditCount;

    /** @var string[] Data needed for time card chart */
    protected $timeCardData;

    /**
     * Revision size data, with keys 'average_size', 'large_edits' and 'small_edits'.
     * @var string[] As returned by the DB, unconverted to int or float
     */
    protected $editSizeData;

    /**
     * Duration of the longest block in days; -1 if indefinite,
     *   or false if could not be parsed from log params
     * @var int|bool
     */
    protected $longestBlockDays;

    /**
     * EditCounter constructor.
     * @param Project $project The base project to count edits
     * @param User $user
     */
    public function __construct(Project $project, User $user)
    {
        $this->project = $project;
        $this->user = $user;
    }

    public function prepareData($container)
    {
        $client = $container->get('guzzle.client.xtools');
        $project = $this->project->getDomain();
        $username = $this->user->getUsername();

        $endpoints = [
            "pair_data" => "ec-pairdata/$project/$username",
            "log_counts" => "ec-logcounts/$project/$username",
            "namespace_totals" => "ec-namespacetotals/$project/$username",
            "edit_sizes" => "ec-editsizes/$project/$username",
            "month_counts" => "ec-monthcounts/$project/$username",
            "automated" => "api/user/automated_editcount/$project/$username",
        ];
        $promises = [];
        $results = [];

        foreach ($endpoints as $key => $endpoint) {
            $promise = $client->getAsync($endpoint);
            $promises[] = $promise;
            $promise->then(function($response) use (&$results, $key) {
                $results[$key] = (array) json_decode($response->getBody()->getContents());
            });
        }

        GuzzleHttp\Promise\settle($promises)->wait();

        // Copy to class instance
        $this->pairData = $results['pair_data'];
        $this->logCounts = $results['log_counts'];
        $this->namespaceTotals = $results['namespace_totals'];
        $this->editSizeData = $results['edit_sizes'];
        $this->monthCounts = $results['month_counts'];
        $this->autoEditCount = $results['automated']['automated_editcount'];

        return $results;
    }

    /**
     * Get revision and page counts etc.
     * @return int[]
     */
    public function getPairData()
    {
        if (!is_array($this->pairData)) {
            $this->pairData = $this->getRepository()
                ->getPairData($this->project, $this->user);
        }
        return $this->pairData;
    }

    /**
     * Get revision dates.
     * @return int[]
     */
    public function getLogCounts()
    {
        if (!is_array($this->logCounts)) {
            $this->logCounts = $this->getRepository()
                ->getLogCounts($this->project, $this->user);
        }
        return $this->logCounts;
    }

    /**
     * Get block data.
     * @param string $type Either 'set' or 'received'.
     * @return array
     */
    public function getBlocks($type)
    {
        if (isset($this->blocks[$type]) && is_array($this->blocks[$type])) {
            return $this->blocks[$type];
        }
        $method = "getBlocks".ucfirst($type);
        $blocks = $this->getRepository()->$method($this->project, $this->user);
        $this->blocks[$type] = $blocks;
        return $this->blocks[$type];
    }

    /**
     * Use Guzzle to get all the general stats at once.
     * @return array Pair data, automated edits
     */
    public function getGeneralStats()
    {
        return $this->getRepository()->getGeneralStats();
    }

    /**
     * Get the total number of currently-live revisions.
     * @return int
     */
    public function countLiveRevisions()
    {
        $revCounts = $this->getPairData();
        return isset($revCounts['live']) ? (int)$revCounts['live'] : 0;
    }

    /**
     * Get the total number of the user's revisions that have been deleted.
     * @return int
     */
    public function countDeletedRevisions()
    {
        $revCounts = $this->getPairData();
        return isset($revCounts['deleted']) ? (int)$revCounts['deleted'] : 0;
    }

    /**
     * Get the total edit count (live + deleted).
     * @return int
     */
    public function countAllRevisions()
    {
        return $this->countLiveRevisions() + $this->countDeletedRevisions();
    }

    /**
     * Get the total number of live revisions with comments.
     * @return int
     */
    public function countRevisionsWithComments()
    {
        $revCounts = $this->getPairData();
        return isset($revCounts['with_comments']) ? (int)$revCounts['with_comments'] : 0;
    }

    /**
     * Get the total number of live revisions without comments.
     * @return int
     */
    public function countRevisionsWithoutComments()
    {
        return $this->countLiveRevisions() - $this->countRevisionsWithComments();
    }

    /**
     * Get the total number of revisions marked as 'minor' by the user.
     * @return int
     */
    public function countMinorRevisions()
    {
        $revCounts = $this->getPairData();
        return isset($revCounts['minor']) ? (int)$revCounts['minor'] : 0;
    }

    /**
     * Get the total number of non-deleted pages edited by the user.
     * @return int
     */
    public function countLivePagesEdited()
    {
        $pageCounts = $this->getPairData();
        return isset($pageCounts['edited-live']) ? (int)$pageCounts['edited-live'] : 0;
    }

    /**
     * Get the total number of deleted pages ever edited by the user.
     * @return int
     */
    public function countDeletedPagesEdited()
    {
        $pageCounts = $this->getPairData();
        return isset($pageCounts['edited-deleted']) ? (int)$pageCounts['edited-deleted'] : 0;
    }

    /**
     * Get the total number of pages ever edited by this user (both live and deleted).
     * @return int
     */
    public function countAllPagesEdited()
    {
        return $this->countLivePagesEdited() + $this->countDeletedPagesEdited();
    }

    /**
     * Get the total number of pages (both still live and those that have been deleted) created
     * by the user.
     * @return int
     */
    public function countPagesCreated()
    {
        return $this->countCreatedPagesLive() + $this->countPagesCreatedDeleted();
    }

    /**
     * Get the total number of pages created by the user, that have not been deleted.
     * @return int
     */
    public function countCreatedPagesLive()
    {
        $pageCounts = $this->getPairData();
        return isset($pageCounts['created-live']) ? (int)$pageCounts['created-live'] : 0;
    }

    /**
     * Get the total number of pages created by the user, that have since been deleted.
     * @return int
     */
    public function countPagesCreatedDeleted()
    {
        $pageCounts = $this->getPairData();
        return isset($pageCounts['created-deleted']) ? (int)$pageCounts['created-deleted'] : 0;
    }

    /**
     * Get the total number of pages that have been deleted by the user.
     * @return int
     */
    public function countPagesDeleted()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['delete-delete']) ? (int)$logCounts['delete-delete'] : 0;
    }

    /**
     * Get the total number of pages moved by the user.
     * @return int
     */
    public function countPagesMoved()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['move-move']) ? (int)$logCounts['move-move'] : 0;
    }

    /**
     * Get the total number of times the user has blocked a user.
     * @return int
     */
    public function countBlocksSet()
    {
        $logCounts = $this->getLogCounts();
        $reBlock = isset($logCounts['block-block']) ? (int)$logCounts['block-block'] : 0;
        return $reBlock;
    }

    /**
     * Get the total number of times the user has re-blocked a user.
     * @return int
     */
    public function countReblocksSet()
    {
        $logCounts = $this->getLogCounts();
        $reBlock = isset($logCounts['block-reblock']) ? (int)$logCounts['block-reblock'] : 0;
        return $reBlock;
    }

    /**
     * Get the total number of times the user has unblocked a user.
     * @return int
     */
    public function countUnblocksSet()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['block-unblock']) ? (int)$logCounts['block-unblock'] : 0;
    }

    /**
     * Get the total number of blocks that have been lifted (i.e. unblocks) by this user.
     * @return int
     */
    public function countBlocksLifted()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['block-unblock']) ? (int)$logCounts['block-unblock'] : 0;
    }

    /**
     * Get the total number of times the user has been blocked.
     * @return int
     */
    public function countBlocksReceived()
    {
        $blocks = $this->getBlocks('received');
        return count($blocks);
    }

    /**
     * Get the length of the longest block the user received.
     * @return int|bool Number of days or false if it could not be determined.
     *                  If the longest duration is indefinite, -1 is returned.
     */
    public function getLongestBlockDays()
    {
        if (isset($this->longestBlockDays)) {
            return $this->longestBlockDays;
        }

        $blocks = $this->getBlocks('received'); // FIXME: make sure this is only called once
        $this->longestBlockDays = false;

        foreach ($blocks as $block) {
            $timestamp = strtotime($block['log_timestamp']);

            // First check if the string is serialized, and if so parse it to get the block duration
            if (@unserialize($block['log_params']) !== false) {
                $parsedParams = unserialize($block['log_params']);
                $durationStr = $parsedParams['5::duration'];
            } else {
                // Old format, the duration in English + block options separated by new lines
                $durationStr = explode("\n", $block['log_params'])[0];
            }

            if (in_array($durationStr, ['indefinite', 'infinity', 'infinite'])) {
                return -1;
            }

            // Try block just in case there are older, unpredictable formats
            try {
                $expiry = strtotime($durationStr, $timestamp);
                $duration = ($expiry - $timestamp) / (60 * 60 * 24);

                if (!$duration || $duration > $this->longestBlockDays) {
                    $this->longestBlockDays = $duration;
                }
            } catch (Exception $error) {
                // do nothing, leaving the longest block at false
            }
        }

        return $this->longestBlockDays;
    }

    /**
     * Get the total number of pages protected by the user.
     * @return int
     */
    public function countPagesProtected()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['protect-protect']) ? (int)$logCounts['protect-protect'] : 0;
    }

    /**
     * Get the total number of pages reprotected by the user.
     * @return int
     */
    public function countPagesReprotected()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['protect-modify']) ? (int)$logCounts['protect-modify'] : 0;
    }

    /**
     * Get the total number of pages unprotected by the user.
     * @return int
     */
    public function countPagesUnprotected()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['protect-unprotect']) ? (int)$logCounts['protect-unprotect'] : 0;
    }

    /**
     * Get the total number of edits deleted by the user.
     * @return int
     */
    public function countEditsDeleted()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['delete-revision']) ? (int)$logCounts['delete-revision'] : 0;
    }

    /**
     * Get the total number of pages restored by the user.
     * @return int
     */
    public function countPagesRestored()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['delete-restore']) ? (int)$logCounts['delete-restore'] : 0;
    }

    /**
     * Get the total number of times the user has modified the rights of a user.
     * @return int
     */
    public function countRightsModified()
    {
        $logCounts = $this->getLogCounts();
        return isset($logCounts['rights-rights']) ? (int)$logCounts['rights-rights'] : 0;
    }

    /**
     * Get the total number of pages imported by the user (through any import mechanism:
     * interwiki, or XML upload).
     * @return int
     */
    public function countPagesImported()
    {
        $logCounts = $this->getLogCounts();
        $import = isset($logCounts['import-import']) ? (int)$logCounts['import-import'] : 0;
        $interwiki = isset($logCounts['import-interwiki']) ? (int)$logCounts['import-interwiki'] : 0;
        $upload = isset($logCounts['import-upload']) ? (int)$logCounts['import-upload'] : 0;
        return $import + $interwiki + $upload;
    }

    /**
     * Get the average number of edits per page (including deleted revisions and pages).
     * @return float
     */
    public function averageRevisionsPerPage()
    {
        if ($this->countAllPagesEdited() == 0) {
            return 0;
        }
        return round($this->countAllRevisions() / $this->countAllPagesEdited(), 3);
    }

    /**
     * Average number of edits made per day.
     * @return float
     */
    public function averageRevisionsPerDay()
    {
        if ($this->getDays() == 0) {
            return 0;
        }
        return round($this->countAllRevisions() / $this->getDays(), 3);
    }

    /**
     * Get the total number of edits made by the user with semi-automating tools.
     */
    public function countAutomatedEdits()
    {
        if ($this->autoEditCount) {
            return $this->autoEditCount;
        }
        $this->autoEditCount = $this->user->countAutomatedEdits($this->project);
        return $this->autoEditCount;
    }

    /**
     * Get the count of (non-deleted) edits made in the given timeframe to now.
     * @param string $time One of 'day', 'week', 'month', or 'year'.
     * @return int The total number of live edits.
     */
    public function countRevisionsInLast($time)
    {
        $revCounts = $this->getPairData();
        return isset($revCounts[$time]) ? $revCounts[$time] : 0;
    }

    /**
     * Get the date and time of the user's first edit.
     * @return DateTime|bool The time of the first revision, or false.
     */
    public function datetimeFirstRevision()
    {
        $revDates = $this->getPairData();
        return isset($revDates['first']) ? new DateTime($revDates['first']) : false;
    }

    /**
     * Get the date and time of the user's first edit.
     * @return DateTime|bool The time of the last revision, or false.
     */
    public function datetimeLastRevision()
    {
        $revDates = $this->getPairData();
        return isset($revDates['last']) ? new DateTime($revDates['last']) : false;
    }

    /**
     * Get the number of days between the first and last edits.
     * If there's only one edit, this is counted as one day.
     * @return int
     */
    public function getDays()
    {
        $first = $this->datetimeFirstRevision();
        $last = $this->datetimeLastRevision();
        if ($first === false || $last === false) {
            return 0;
        }
        $days = $last->diff($first)->days;
        return $days > 0 ? $days : 1;
    }

    /**
     * Get the total number of files uploaded (including those now deleted).
     * @return int
     */
    public function countFilesUploaded()
    {
        $logCounts = $this->getLogCounts();
        return $logCounts['upload-upload'] ?: 0;
    }

    /**
     * Get the total number of files uploaded to Commons (including those now deleted).
     * This is only applicable for WMF labs installations.
     * @return int
     */
    public function countFilesUploadedCommons()
    {
        $logCounts = $this->getLogCounts();
        return $logCounts['files_uploaded_commons'] ?: 0;
    }

    /**
     * Get the total number of revisions the user has sent thanks for.
     * @return int
     */
    public function thanks()
    {
        $logCounts = $this->getLogCounts();
        return $logCounts['thanks-thank'] ?: 0;
    }

    /**
     * Get the total number of approvals
     * @return int
     */
    public function approvals()
    {
        $logCounts = $this->getLogCounts();
        $total = $logCounts['review-approve'] +
        (!empty($logCounts['review-approve-a']) ? $logCounts['review-approve-a'] : 0) +
        (!empty($logCounts['review-approve-i']) ? $logCounts['review-approve-i'] : 0) +
        (!empty($logCounts['review-approve-ia']) ? $logCounts['review-approve-ia'] : 0);
        return $total;
    }

    /**
     * Get the total number of patrols performed by the user.
     * @return int
     */
    public function patrols()
    {
        $logCounts = $this->getLogCounts();
        return $logCounts['patrol-patrol'] ?: 0;
    }

    /**
     * Get the total number of accounts created by the user.
     * @return int
     */
    public function accountsCreated()
    {
        $logCounts = $this->getLogCounts();
        $create2 = $logCounts['newusers-create2'] ?: 0;
        $byemail = $logCounts['newusers-byemail'] ?: 0;
        return $create2 + $byemail;
    }

    /**
     * Get the given user's total edit counts per namespace.
     * @return integer[] Array keys are namespace IDs, values are the edit counts.
     */
    public function namespaceTotals()
    {
        if ($this->namespaceTotals) {
            return $this->namespaceTotals;
        }
        $counts = $this->getRepository()->getNamespaceTotals($this->project, $this->user);
        arsort($counts);
        $this->namespaceTotals = $counts;
        return $counts;
    }

    /**
     * Get a summary of the times of day and the days of the week that the user has edited.
     * @return string[]
     */
    public function timeCard()
    {
        if ($this->timeCardData) {
            return $this->timeCardData;
        }
        $totals = $this->getRepository()->getTimeCard($this->project, $this->user);
        $this->timeCardData = $totals;
        return $totals;
    }

    /**
     * Get the total numbers of edits per month.
     * @param null|DateTime [$currentTime] - *USED ONLY FOR UNIT TESTING*
     *   so we can mock the current DateTime.
     * @return mixed[] With keys 'yearLabels', 'monthLabels' and 'totals',
     *   the latter keyed by namespace, year and then month.
     */
    public function monthCounts($currentTime = null)
    {
        if (isset($this->monthCounts)) {
            return $this->monthCounts;
        }

        // Set to current month if we're not unit-testing
        if (!($currentTime instanceof DateTime)) {
            $currentTime = new DateTime('last day of this month');
        }

        $totals = $this->getRepository()->getMonthCounts($this->project, $this->user);
        $out = [
            'yearLabels' => [],  // labels for years
            'monthLabels' => [], // labels for months
            'totals' => [], // actual totals, grouped by namespace, year and then month
        ];

        /** @var DateTime Keep track of the date of their first edit. */
        $firstEdit = new DateTime();

        // Loop through the database results and fill in the values
        //   for the months that we have data for.
        foreach ($totals as $total) {
            // Keep track of first edit
            $date = new DateTime($total['year'].'-'.$total['month'].'-01');
            if ($date < $firstEdit) {
                $firstEdit = $date;
            }

            // Collate the counts by namespace, and then year and month.
            $ns = $total['page_namespace'];
            if (!isset($out['totals'][$ns])) {
                $out['totals'][$ns] = [];
            }

            // Start array for this year if not already present.
            if (!isset($out['totals'][$ns][$total['year']])) {
                $out['totals'][$ns][$total['year']] = [];
            }

            $out['totals'][$ns][$total['year']][$total['month']] = (int) $total['count'];
        }

        $dateRange = new DatePeriod(
            $firstEdit,
            new DateInterval('P1M'),
            $currentTime->modify('first day of this month')
        );

        foreach ($dateRange as $monthObj) {
            $year = (int) $monthObj->format('Y');
            $month = (int) $monthObj->format('n');

            // Fill in labels
            $out['monthLabels'][] = $monthObj->format('Y-m');
            if (!in_array($year, $out['yearLabels'])) {
                $out['yearLabels'][] = $year;
            }

            foreach (array_keys($out['totals']) as $nsId) {
                if (!isset($out['totals'][$nsId][$year])) {
                    $out['totals'][$nsId][$year] = [];
                }

                if (!isset($out['totals'][$nsId][$year][$month])) {
                    $out['totals'][$nsId][$year][$month] = 0;
                }
            }
        }

        // One more set of loops to sort by year/month
        foreach (array_keys($out['totals']) as $nsId) {
            ksort($out['totals'][$nsId]);

            foreach ($out['totals'][$nsId] as &$yearData) {
                ksort($yearData);
            }
        }

        // Finally, sort the namespaces
        ksort($out['totals']);

        $this->monthCounts = $out;
        return $out;
    }

    /**
     * Get the total numbers of edits per year.
     * @param null|DateTime [$currentTime] - *USED ONLY FOR UNIT TESTING*
     *   so we can mock the current DateTime.
     * @return mixed[] With keys 'yearLabels' and 'totals', the latter
     *   keyed by namespace then year.
     */
    public function yearCounts($currentTime = null)
    {
        if (isset($this->yearCounts)) {
            return $this->yearCounts;
        }

        $out = $this->monthCounts($currentTime);

        foreach ($out['totals'] as $nsId => $years) {
            foreach ($years as $year => $months) {
                $out['totals'][$nsId][$year] = array_sum(array_values($months));
            }
        }

        $this->yearCounts = $out;
        return $out;
    }

    /**
     * Get the total edit counts for the top n projects of this user.
     * @param int $numProjects
     * @return mixed[] Each element has 'total' and 'project' keys.
     */
    public function globalEditCountsTopN($numProjects = 10)
    {
        // Get counts.
        $editCounts = $this->globalEditCounts(true);
        // Truncate, and return.
        return array_slice($editCounts, 0, $numProjects);
    }

    /**
     * Get the total number of edits excluding the top n.
     * @param int $numProjects
     * @return int
     */
    public function globalEditCountWithoutTopN($numProjects = 10)
    {
        $editCounts = $this->globalEditCounts(true);
        $bottomM = array_slice($editCounts, $numProjects);
        $total = 0;
        foreach ($bottomM as $editCount) {
            $total += $editCount['total'];
        }
        return $total;
    }

    /**
     * Get the grand total of all edits on all projects.
     * @return int
     */
    public function globalEditCount()
    {
        $total = 0;
        foreach ($this->globalEditCounts() as $editCount) {
            $total += $editCount['total'];
        }
        return $total;
    }

    /**
     * Get the total revision counts for all projects for this user.
     * @param bool $sorted Whether to sort the list by total, or not.
     * @return mixed[] Each element has 'total' and 'project' keys.
     */
    public function globalEditCounts($sorted = false)
    {
        if (empty($this->globalEditCounts)) {
            $this->globalEditCounts = $this->getRepository()
                ->globalEditCounts($this->user, $this->project);
            if ($sorted) {
                // Sort.
                uasort($this->globalEditCounts, function ($a, $b) {
                    return $b['total'] - $a['total'];
                });
            }
        }
        return $this->globalEditCounts;
    }

    /**
     * Get the most recent n revisions across all projects.
     * @param int $max The maximum number of revisions to return.
     * @return Edit[]
     */
    public function globalEdits($max)
    {
        // Collect all projects with any edits.
        $projects = [];
        foreach ($this->globalEditCounts() as $editCount) {
            // Don't query revisions if there aren't any.
            if ($editCount['total'] == 0) {
                continue;
            }
            $projects[$editCount['project']->getDatabaseName()] = $editCount['project'];
        }

        // Get all revisions for those projects.
        $globalRevisionsData = $this->getRepository()
            ->getRevisions($projects, $this->user, $max);
        $globalEdits = [];
        foreach ($globalRevisionsData as $revision) {
            /** @var Project $project */
            $project = $projects[$revision['project_name']];
            $nsName = '';
            if ($revision['page_namespace']) {
                $nsName = $project->getNamespaces()[$revision['page_namespace']];
            }
            $page = $project->getRepository()
                ->getPage($project, $nsName . ':' . $revision['page_title']);
            $edit = new Edit($page, $revision);
            $globalEdits[$edit->getTimestamp()->getTimestamp().'-'.$edit->getId()] = $edit;
        }

        // Sort and prune, before adding more.
        krsort($globalEdits);
        $globalEdits = array_slice($globalEdits, 0, $max);
        return $globalEdits;
    }

    /**
     * Get average edit size, and number of large and small edits.
     * @return int[]
     */
    public function getEditSizeData()
    {
        if (!is_array($this->editSizeData)) {
            $this->editSizeData = $this->getRepository()
                ->getEditSizeData($this->project, $this->user);
        }
        return $this->editSizeData;
    }

    /**
     * Get the total edit count of this user or 5,000 if they've made more than 5,000 edits.
     * This is used to ensure percentages of small and large edits are computed properly.
     * @return int
     */
    public function countLast5000()
    {
        return $this->countLiveRevisions() > 5000 ? 5000 : $this->countLiveRevisions();
    }

    /**
     * Get the number of edits under 20 bytes of the user's past 5000 edits.
     * @return int
     */
    public function countSmallEdits()
    {
        $editSizeData = $this->getEditSizeData();
        return isset($editSizeData['small_edits']) ? (int) $editSizeData['small_edits'] : 0;
    }

    /**
     * Get the total number of edits over 1000 bytes of the user's past 5000 edits.
     * @return int
     */
    public function countLargeEdits()
    {
        $editSizeData = $this->getEditSizeData();
        return isset($editSizeData['large_edits']) ? (int) $editSizeData['large_edits'] : 0;
    }

    /**
     * Get the average size of the user's past 5000 edits.
     * @return float Size in bytes.
     */
    public function averageEditSize()
    {
        $editSizeData = $this->getEditSizeData();
        if (isset($editSizeData['average_size'])) {
            return round($editSizeData['average_size'], 3);
        } else {
            return 0;
        }
    }
}
