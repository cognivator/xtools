<?php
/**
 * This file contains only the SimpleEditCounterController class.
 */

namespace AppBundle\Controller;

use Doctrine\DBAL\Connection;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xtools\Project;
use Xtools\ProjectRepository;
use Xtools\User;

/**
 * This controller handles the Simple Edit Counter tool.
 */
class SimpleEditCounterController extends XtoolsController
{

    /**
     * Get the tool's shortname.
     * @return string
     * @codeCoverageIgnore
     */
    public function getToolShortname()
    {
        return 'sc';
    }

    /**
     * The Simple Edit Counter search form.
     * @Route("/sc", name="sc")
     * @Route("/sc", name="SimpleEditCounter")
     * @Route("/sc/", name="SimpleEditCounterSlash")
     * @Route("/sc/index.php", name="SimpleEditCounterIndexPhp")
     * @Route("/sc/{project}", name="SimpleEditCounterProject")
     * @param Request $request The HTTP request.
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $params = $this->parseQueryParams($request);

        // Redirect if project and username are given.
        if (isset($params['project']) && isset($params['username'])) {
            return $this->redirectToRoute('SimpleEditCounterResult', $params);
        }

        // Convert the given project (or default project) into a Project instance.
        $params['project'] = $this->getProjectFromQuery($params);

        // Show the form.
        return $this->render('simpleEditCounter/index.html.twig', [
            'xtPageTitle' => 'tool-sc',
            'xtSubtitle' => 'tool-sc-desc',
            'xtPage' => 'sc',
            'project' => $params['project'],
        ]);
    }

    /**
     * Display the
     * @Route("/sc/{project}/{username}", name="SimpleEditCounterResult")
     * @param Request $request The HTTP request.
     * @return Response
     * @codeCoverageIgnore
     */
    public function resultAction(Request $request)
    {
        $ret = $this->validateProjectAndUser($request);
        if ($ret instanceof RedirectResponse) {
            return $ret;
        } else {
            list($project, $user) = $ret;
        }

        $userTable = $project->getTableName('user');
        $archiveTable = $project->getTableName('archive');
        $revisionTable = $project->getTableName('revision');
        $userGroupsTable = $project->getTableName('user_groups');

        /** @var Connection $conn */
        $conn = $this->get('doctrine')->getManager('replicas')->getConnection();

        // Prepare the query and execute
        $resultQuery = $conn->prepare("
            SELECT 'id' AS source, user_id as value
                FROM $userTable
                WHERE user_name = :username
            UNION
            SELECT 'arch' AS source, COUNT(*) AS value
                FROM $archiveTable
                WHERE ar_user_text = :username
            UNION
            SELECT 'rev' AS source, COUNT(*) AS value
                FROM $revisionTable
                WHERE rev_user_text = :username
            UNION
            SELECT 'groups' AS source, ug_group AS value
                FROM $userGroupsTable
                JOIN $userTable ON user_id = ug_user
                WHERE user_name = :username
        ");

        $username = $user->getUsername();
        $resultQuery->bindParam('username', $username);
        $resultQuery->execute();

        // Fetch the result data
        $results = $resultQuery->fetchAll();

        // Initialize the variables - just so we don't get variable undefined errors if there is a problem
        $id = '';
        $arch = '';
        $rev = '';
        $groups = '';

        // Iterate over the results, putting them in the right variables
        foreach ($results as $row) {
            if ($row['source'] == 'id') {
                $id = $row['value'];
            }
            if ($row['source'] == 'arch') {
                $arch = $row['value'];
            }
            if ($row['source'] == 'rev') {
                $rev = $row['value'];
            }
            if ($row['source'] == 'groups') {
                $groups .= $row['value']. ', ';
            }
        }

        // Unknown user - If the user is created the $results variable will have 3 entries.
        // This is a workaround to detect non-existent IPs.
        if (count($results) < 3 && $arch == 0 && $rev == 0) {
            $this->addFlash('notice', [ 'no-result', $username]);

            return $this->redirectToRoute('SimpleEditCounterProject', [ 'project' => $project->getDomain() ]);
        }

        // Remove the last comma and space
        if (strlen($groups) > 2) {
            $groups = substr($groups, 0, -2);
        }

        // If the user isn't in any groups, show a message.
        if (strlen($groups) == 0) {
            $groups = '---';
        }

        $globalGroups = '';

        if (boolval($this->getParameter('app.single_wiki'))) {
            // Retrieving the global groups, using the ApiHelper class
            $api = $this->get('app.api_helper');
            $globalGroups = $api->globalGroups($project->getUrl(), $username);
        }

        // Assign the values and display the template
        return $this->render('simpleEditCounter/result.html.twig', [
            'xtPage' => 'sc',
            'xtTitle' => $username,
            'user' => $user,
            'project' => $project,
            'id' => $id,
            'arch' => $arch,
            'rev' => $rev + $arch,
            'live' => $rev,
            'groups' => $groups,
            'globalGroups' => $globalGroups,
        ]);
    }
}
