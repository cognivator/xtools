<?php

namespace Xtools;

use Mediawiki\Api\SimpleRequest;
use Symfony\Component\DependencyInjection\Container;

class UserRepository extends Repository
{

    /** @var int[] */
    protected $userIds;

    /**
     * Convenience method to get a new User object.
     * @param string $username
     * @return User
     */
    public static function getUser($username, Container $container)
    {
        $user = new User($username);
        $userRepo = new UserRepository();
        $userRepo->setContainer($container);
        $user->setRepository($userRepo);
        return $user;
    }

    /**
     * Get the user's ID.
     * @param string $databaseName The database to query.
     * @param string $username The username to find.
     * @return int
     */
    public function getId($databaseName, $username)
    {
        if (isset($this->userIds[$databaseName][$username])) {
            return $this->userIds[$databaseName][$username];
        }
        $userTable = $this->getTableName($databaseName, 'user');
        $sql = "SELECT user_id FROM $userTable WHERE user_name = :username LIMIT 1";
        $resultQuery = $this->getProjectsConnection()->prepare($sql);
        $resultQuery->bindParam("username", $username);
        $resultQuery->execute();
        $userId = (int)$resultQuery->fetchColumn();
        $this->userIds[$databaseName][$username] = $userId;
        return $userId;
    }

    /**
     * @param Project $project
     * @param string $username
     * @return array
     */
    public function getGroups(Project $project, $username)
    {
        $cacheKey = 'usergroups.'.$project->getDatabaseName().'.'.$username;
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $this->stopwatch->start($cacheKey, 'XTools');
        $api = $this->getMediawikiApi($project);
        $params = [ "list"=>"users", "ususers"=>$username, "usprop"=>"groups" ];
        $query = new SimpleRequest('query', $params);
        $result = [];
        $res = $api->getRequest($query);
        if (isset($res["batchcomplete"]) && isset($res["query"]["users"][0]["groups"])) {
            $result = $res["query"]["users"][0]["groups"];
        }

        // Cache for 10 minutes, and return.
        $cacheItem = $this->cache->getItem($cacheKey)
            ->set($result)
            ->expiresAfter(new \DateInterval('PT10M'));
        $this->cache->save($cacheItem);
        $this->stopwatch->stop($cacheKey);

        return $result;
    }

    /**
     * Get a user's global group membership (starting at XTools' default project if none is
     * provided). This requires the CentralAuth extension to be installed.
     * @link https://www.mediawiki.org/wiki/Extension:CentralAuth
     * @param string $username The username.
     * @param Project $project The project to query.
     * @return string[]
     */
    public function getGlobalGroups($username, Project $project = null)
    {
        // Get the default project if not provided.
        if (!$project instanceof Project) {
            $project = ProjectRepository::getDefaultProject($this->container);
        }

        // Create the API query.
        $api = $this->getMediawikiApi($project);
        $params = [ "meta"=>"globaluserinfo", "guiuser"=>$username, "guiprop"=>"groups" ];
        $query = new SimpleRequest('query', $params);

        // Get the result.
        $res = $api->getRequest($query);
        $result = [];
        if (isset($res["batchcomplete"]) && isset($res["query"]["globaluserinfo"]["groups"])) {
            $result = $res["query"]["globaluserinfo"]["groups"];
        }
        return $result;
    }
}