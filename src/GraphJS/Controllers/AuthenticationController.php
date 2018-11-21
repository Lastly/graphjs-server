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
use PhoNetworksAutogenerated\User;
use Mailgun\Mailgun;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;


 /**
 * Takes care of Authentication
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
class AuthenticationController extends AbstractController
{

    public function signupViaToken(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $token_key = Key::loadFromAsciiSafeString($key);
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'token' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Valid username, email are required.");
            return;
        }
        if(!preg_match("/^[a-zA-Z0-9_]{1,12}$/", $data["username"])) {
            $this->fail($response, "Invalid username");
            return;
        }
        try {
            $username = Crypto::decrypt($data["token"], $token_key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        if($username!=$data["username"]) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8));
        error_log("sign up password is ".$password);
        $this->actualSignup($request,  $response,  $session,  $kernel, $username, $data["email"], $password);
    }

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
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if($validation->fails()) {
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
        $this->actualSignup( $request,  $response,  $session,  $kernel, $data["username"], $data["email"], $data["password"]);
    }

    protected function actualSignup(Request $request, Response $response, Session $session, Kernel $kernel, string $username, string $email, string $password): void
    {
        try {
            $new_user = new User(
                $kernel, $kernel->graph(), $username, $email, $password
            );
        } catch(\Exception $e) {
            $this->fail($response, $e->getMessage());
            return;
        }
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
        $validation = $this->validator->validate($data, [
            'username' => 'required',
            'password' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Username and password fields are required.");
            return;
        }

        $this->actualLogin($request, $response, $session, $kernel, $data["username"], $data["password"]);

    }

    /**
     * Log In Via Token
     * 
     * [token]
     *
     * @param Request  $request
     * @param Response $response
     * @param Session  $session
     * @param Kernel   $kernel
     * 
     * @return void
     */
    public function loginViaToken(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        $key = getenv("SINGLE_SIGNON_TOKEN_KEY") ? getenv("SINGLE_SIGNON_TOKEN_KEY") : "";
        if(empty($key)) {
            return $this->fail($response, "Single sign-on not allowed");
        }
        $token_key = Key::loadFromAsciiSafeString($key);
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'token' => 'required',
        ]);
        if($validation->fails()) {
            $this->fail($response, "Token field is required.");
            return;
        }
        try {
            $username = Crypto::decrypt($data["token"], $token_key);
        }
        catch(\Exception $e) {
            return $this->fail($response, "Invalid token");
        }
        $password = str_replace(["/","\\"], "", substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8)); // substr(password_hash($username, PASSWORD_BCRYPT, ["salt"=>$key]), -8);
        error_log("username is: ".$username."\npassword is: ".$password);
        
        $this->actualLogin($request, $response, $session, $kernel, $username, $password);
        
    }

    protected function actualLogin(Request $request, Response $response, Session $session, Kernel $kernel, string $username, string $password): void
    {
        $result = $kernel->index()->query(
            "MATCH (n:user {Username: {username}, Password: {password}}) RETURN n",
            [ 
                "username" => $username,
                "password" => md5($password)
            ]
        );
        error_log(print_r($result, true));
        $success = (count($result->results()) >= 1);
        if(!$success) {
            error_log("failing!!! ");
            $this->fail($response, "Information don't match records");
            return;
        }
        error_log("is a  success");
        $user = $result->results()[0];
        error_log(print_r($user));
        $session->set($request, "id", $user["n.udid"]);
        $this->succeed(
            $response, [
                "id" => $user["n.udid"]
            ]
        );
        error_log("is a  success");
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
    public function whoami(Request $request, Response $response, Session $session, Kernel $kernel)
    {
        if(!is_null($id = $this->dependOnSession(...\func_get_args()))) {
            try {
                $i = $kernel->gs()->node($id);
            }
            catch(\Exception $e) {
                return $this->fail($response, "Invalid user");
            }
            $this->succeed($response, [
                    "id" => $id, 
                    "editor" => ( 
                        (($id==$kernel->founder()->id()->toString())) 
                        || 
                        (isset($i->attributes()->is_editor) && (bool) $i->attributes()->is_editor)
                    )
                ]
            );
        }
    }

    public function reset(Request $request, Response $response)
    {
        $data = $request->getQueryParams();
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
        ]);
        if($validation->fails()) {
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
        $validation = $this->validator->validate($data, [
            'email' => 'required|email',
            'code' => 'required',
        ]);
        if($validation->fails()||!preg_match("/^[0-9]{6}$/", $data["code"])) {
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
 
         
         $result = $kernel->index()->query(
            "MATCH (n:user {Email: {email}}) RETURN n",
            [ 
                "email" => $data["email"]
            ]
        );
        $success = (count($result->results()) >= 1);
        if(!$success) {
            $this->fail($response, "This user is not registered");
            return;
        }
        $user = $result->results()[0];
        $session->set($request, "id", $user["n.udid"]);
         
         
            $this->succeed($response);
        }
        $this->fail($response, "Code does not match.");
    }

}
