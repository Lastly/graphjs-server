<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

 namespace GraphJS\Controllers;

use CapMousse\ReactRestify\Http\Request;
use CapMousse\ReactRestify\Http\Response;
use Pho\Kernel\Kernel;
use Valitron\Validator;
use PhoNetworksAutogenerated\User;
use PhoNetworksAutogenerated\UserOut\Star;
use PhoNetworksAutogenerated\UserOut\Comment;
use Pho\Lib\Graph\ID;


/**
 * Administrative calls go here.
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class AdministrationController extends AbstractController
{

    protected function requireAdministrativeRights(Request $request, Response $response, Kernel $kernel): bool
    {
        $founder = $kernel->founder();
        $hash = md5(strtolower(sprintf("%s:%s", $founder->getEmail(), $founder->getPassword())));
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['hash']);
        //$v->rule('length', [['hash', 32]]);
        if(!$v->validate()||$data["hash"]!=$hash) {
            return false;
        }
        return true;
    }

    protected function _getPendingComments(Kernel $kernel): array
    {
        $pending_comments = [];
        $res = $kernel->index()->client()->run("MATCH (a:user)-[e:comment {Pending: true}]-(n:page) RETURN a.udid AS author_id, a.Email AS author_email, n.udid AS page_id, e.udid AS comment_id, n.Url AS page_url, n.Title AS page_title, e.Content AS comment");
        $array = $res->records();
        foreach($array as $a) {
            $pending_comments[$a->value("comment_id")] = [
                "author_id" => $a->value("author_id"), 
                "author_email" => $a->value("author_email"), 
                "page_id" => $a->value("page_id"), 
                "page_url" => $a->value("page_url"),
                "page_title" => $a->value("page_title"),
                "comment" => $a->value("comment"),
            ];
        }
        return $pending_comments;
    }

    public function fetchAllPendingComments(Request $request, Response $response, Kernel $kernel)
    {
        if(!$this->requireAdministrativeRights(...\func_get_args()))
            return $this->fail($response, "Invalid hash");
        $is_moderated = ($kernel->graph()->getCommentsModerated() === true);
        /*if(!$is_moderated)
            return $this->fail($response);
        */
        $pending_comments = $this->_getPendingComments($kernel);
        $this->succeed($response, ["pending_comments"=>$pending_comments]);
    }

    /**
     * @todo Check for admin capabilities
     */
    public function approvePendingComment(Request $request, Response $response, Kernel $kernel)
    {
        if(!$this->requireAdministrativeRights(...\func_get_args()))
            return $this->fail($response, "Invalid hash");
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['comment_id']);
        if(!$v->validate()) {
            $this->fail($response, "comment_id required");
            return;
        }
        try {
            $comment = $kernel->gs()->edge($data["comment_id"]);
        }
        catch(\Exception $e) {
            $this->fail($response, "Invalid Comment ID.");
            return;
        }
        if(!$comment instanceof Comment)
            return $this->fail($response, "Invalid Comment.");
        $comment->setPending(false);
        $this->succeed($response);
    }

    public function setCommentModeration(Request $request, Response $response, Kernel $kernel)
    {
        if(!$this->requireAdministrativeRights(...\func_get_args()))
            return $this->fail($response, "Invalid hash");
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['moderated']);
        //$v->rule('boolean', ['moderated']);
        if(!$v->validate()) {
            return $this->fail($response, "A boolean 'moderated' field is required");
        }
        $is_moderated = (bool) $data["moderated"];
        if(!$is_moderated) {
            $pending_comments = $this->_getPendingComments($kernel);
            foreach($pending_comments as $c_id=>$c) {
                try {
                    $comment = $kernel->gs()->edge($c_id);
                    $comment->setPending(false);
                }
                catch (\Exception $e) {
                    error_log("a-oh can't fetch comment id ".$c_id);
                }
            }
        }
        $kernel->graph()->setCommentsModerated($is_moderated);
        $kernel->graph()->persist();
        $this->succeed($response);
    }

    public function getCommentModeration(Request $request, Response $response, Kernel $kernel)
    {
        if(!$this->requireAdministrativeRights(...\func_get_args()))
            return $this->fail($response, "Invalid hash");
        $is_moderated = (bool) $kernel->graph()->getCommentsModerated();
        $this->succeed($response, ["is_moderated"=>$is_moderated]);
    }

}