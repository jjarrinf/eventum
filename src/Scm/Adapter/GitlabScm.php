<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Scm\Adapter;

use Date_Helper;
use Eventum\Model\Entity;

/**
 * Gitlab SCM handler
 *
 * @link http://doc.gitlab.com/ce/web_hooks/web_hooks.html
 * @package Eventum\Scm\Adapter
 */
class GitlabScm extends AbstractScmAdapter
{
    const GITLAB_HEADER = 'X-Gitlab-Event';

    /**
     * @inheritdoc
     */
    public function can()
    {
        return $this->request->headers->has(self::GITLAB_HEADER);
    }

    /**
     * @inheritdoc
     */
    public function process()
    {
        $eventType = $this->request->headers->get(self::GITLAB_HEADER);

        if ($eventType == 'Push Hook') {
            $this->processPushHook();
        }
    }

    /**
     * Walk over commit messages and match issue ids
     */
    private function processPushHook()
    {
        $payload = $this->getPayload();
        $this->log->debug('processPushHook', array('payload' => $payload));
        $project = $payload['project']['path_with_namespace'];

        foreach ($payload['commits'] as $commit) {
            $issues = $this->match_issues($commit['message']);
            if (!$issues) {
                continue;
            }
            $this->log->debug('commit', array('issues' => $issues, 'commit' => $commit));

            $ci = Entity\Commit::create()
                ->setScmName($project)
                ->setCommitId($commit['id'])
                ->setAuthorEmail($commit['author']['email'])
                ->setAuthorName($commit['author']['name'])
                ->setCommitDate(Date_Helper::getDateTime($commit['timestamp']))
                ->setMessage(trim($commit['message']));
            $ci->save();

            foreach ($commit['added'] as $file) {
                $cf = Entity\CommitFile::create()
                    ->setProjectName($project)
                    ->setCommitId($ci->getId())
                    ->setFilename($file);
                $cf->save();
                $ci->addFile($cf);
            }
            foreach ($commit['modified'] as $file) {
                $cf = Entity\CommitFile::create()
                    ->setCommitId($ci->getId())
                    ->setProjectName($project)
                    ->setFilename($file);
                $cf->save();
                $ci->addFile($cf);
            }
            foreach ($commit['removed'] as $file) {
                $cf = Entity\CommitFile::create()
                    ->setProjectName($project)
                    ->setCommitId($ci->getId())
                    ->setFilename($file);
                $cf->save();
                $ci->addFile($cf);
            }

            foreach ($issues as $issue_id) {
                Entity\IssueCommit::create()
                    ->setCommitId($ci->getId())
                    ->setIssueId($issue_id)
                    ->save();
            }
        }
    }

    /*
     * Get Hook Payload
     */
    private function getPayload()
    {
        return json_decode($this->request->getContent(), true);
    }
}
