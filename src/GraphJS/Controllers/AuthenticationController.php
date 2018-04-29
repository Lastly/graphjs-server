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
use CapMousse\ReactRestify\Http\Session;
use Pho\Kernel\Kernel;
use Valitron\Validator;
use PhoNetworksAutogenerated\User;
use Mailgun\Mailgun;


/**
 * Takes care of Authentication
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class AuthenticationController extends AbstractController
{
    /**
     * Sign Up
     * 
     * [username, email, password]
     * 
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function signup(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['username', 'email', 'password']);
        $v->rule('email', 'email');
        if(!$v->validate()) {
            $this->fail($response, "Valid username, email and password required.");
            return;
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            $this->fail($response, "Invalid username");
            return;
        }
        if(!preg_match("/[0-9A-Za-z!@#$%_]{5,15}/", $data["password"])) {
            $this->fail($response, "Invalid password");
            return;
        }
        $new_user = new User(
            $kernel, $kernel->graph(), $data["username"], $data["email"], $data["password"]
        );
        $session->set($request, "id", (string) $new_user->id());
        
        $this->succeed(
            $response, [
            "id" => (string) $new_user->id()
            ]
        );
    }

    /**
     * Log In
     * 
     * [username, password]
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function login(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['username', 'password']);
        //$v->rule('email', 'email');
        if(!$v->validate()) {
            $this->fail($response, "Username and password fields are required.");
            return;
        }
        $result = $kernel->index()->query(
            "MATCH (n:user {Username: {username}, Password: {password}}) RETURN n",
            [ 
                "username" => $data["username"],
                "password" => md5($data["password"])
            ]
        );
        $success = (count($result->results()) == 1);
        if(!$success) {
            $this->fail($response, "Information don't match records");
            return;
        }
        $user = $result->results()[0];
        $session->set($request, "id", $user["udid"]);
        $this->succeed(
            $response, [
            "id" => $user["udid"]
            ]
        );
    }

    /**
     * Log Out
     *
     * @param  Request  $request
     * @param  Response $response
     * @param  Session  $session
     * @return void
     */
    public function logout(Request $request, Response $response, Session $session) 
    {
        $session->set($request, "id", null);
        $this->succeed($response);
    }

    /**
     * Who Am I?
     * 
     * @param  Request  $request
     * @param  Response $response
     * @param  Session  $session
     * @return void
     */
    public function whoami(Request $request, Response $response, Session $session)
    {
        if(!is_null($id = $this->dependOnSession(...\func_get_args()))) {
            $this->succeed($response, ["id" => $id]);
        }
    }

    public function reset(Request $request, Response $response)
    {
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['email']);
        $v->rule('email', 'email');
        if(!$v->validate()) {
            $this->fail($response, "Valid email required.");
            return;
        }
        // check if email exists ?
        $pin = mt_rand(100000, 999999);
        file_put_contents(getenv("PASSWORD_REMINDER").md5($data["email"]), "{$pin}:".time()."\n", LOCK_EX);
        $mgClient = new Mailgun(getenv("MAILGUN_KEY")); 
        $mgClient->sendMessage(getenv("MAILGUN_DOMAIN"),
          array('from'    => 'GraphJS <postmaster@mg.graphjs.com>',
                'to'      => $data["email"],
                'subject' => 'Password Reminder',
                'text'    => 'You may enter this 6 digit passcode: '.$pin)
        );
        $this->succeed($response);
    }

    public function verify(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $data = $request->getQueryParams();
        $v = new Validator($data);
        $v->rule('required', ['email', 'code']);
        $v->rule('email', 'email');
        if(!$v->validate()||!preg_match("/^[0-9]{6}$/", $data["code"])) {
            $this->fail($response, "Valid email and code required.");
            return;
        }
        $pins = explode(":", trim(file_get_contents(getenv("PASSWORD_REMINDER").md5($data["email"]))));
        //error_log(print_r($pins, true));
        if($pins[0]==$data["code"]) {
            if((int) $pins[1]<time()-7*60) {
                $this->fail($response, "Expired.");
                return;
            }
            $this->succeed($response);
        }
        $this->fail($response, "Code does not match.");
    }

}