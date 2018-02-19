<?php
/**
 *  This file is part of the Xpressengine package.
 *
 * @category
 * @package     Xpressengine\
 * @author      XE Developers (khongchi) <khongchi@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER Crop. <http://www.navercorp.com>
 * @license     LGPL-2.1
 * @license     http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html
 * @link        https://xpressengine.io
 */

namespace Xpressengine\Plugins\SocialLogin\Authenticators;

use Auth;
use Laravel\Socialite\SocialiteManager;
use XeDB;
use Xpressengine\Support\Exceptions\XpressengineException;
use Xpressengine\User\Models\User;
use Xpressengine\User\UserInterface;

/**
 * @category
 * @package     ${NAMESPACE}
 */
class AbstractAuth
{
    protected $provider;

    public function __construct($provider)
    {
        $this->socialite = new SocialiteManager(app());
        $this->provider = $provider;
        $this->extendProvider();
    }

    protected function extendProvider()
    {
    }

    /**
     * getCallbackParameter
     *
     * @return string
     */
    public function getCallbackParameter()
    {
        return 'code';
    }

    public function execute($hasCode)
    {
        if (!$hasCode) {
            return $this->authorization();
        }

        // get user info from oauth server
        $userInfo = $this->getAuthenticatedUser();

        if (\Auth::check() === false) {
            return $this->login($userInfo);
        } else {
            return $this->connect($userInfo);
        }
    }

    public function login($userInfo)
    {
        // if authorized user info is not saved completely, save user info.
        try {
            $userData = $this->resolveUserInfo($userInfo);

            // if user not exist, redirect to register page after saving token.
            if(!$user = $this->resolveUser($userData)) {
                $config = app('xe.config')->get('user.join');
                $joinGroup = $config->get('joinGroup');
                if ($joinGroup !== null) {
                    $userData['group_id'] = [$joinGroup];
                }

                $user = app('xe.user')->create($userData);
            }
        } catch (\Exception $e) {
            throw $e;
        }

        // check user's status
        if ($user->getStatus() === User::STATUS_ACTIVATED) {
            $this->loginMember($user);
        } else {
            return redirect()->route('login')->with('alert', [
                'type' => 'danger',
                'message' => xe_trans('social_login::disabledAccount')
            ]);
        }

        return redirect()->intended('/');
    }

    /**
     * connect
     *
     * @param $userInfo
     *
     * @return string
     * @throws \Exception
     */
    public function connect($userInfo)
    {
        $user = Auth::user();
        $this->connectToUser($user, $userInfo);

        return "
            <script>
                window.opener.location.reload();
                window.close();
            </script>
        ";
    }

    public function disconnect()
    {
        $user = Auth::user();

        $account = $user->getAccountByProvider($this->provider);

        app('xe.user')->deleteAccount($account);
    }

    private function authorization()
    {
        return $this->socialite->driver($this->provider)->redirect();
    }

    public function getAuthenticatedUser($token = null, $tokenSecret = null)
    {

        $provider = $this->socialite->driver($this->provider);
        if($token !== null) {

            if ($provider instanceof \Laravel\Socialite\One\AbstractProvider) {
                return $provider->userFromTokenAndSecret($token, $tokenSecret);
            } else if ($provider instanceof \Laravel\Socialite\Two\AbstractProvider) {
                return $provider->userFromToken($token);
            }
        }
        return $provider->user();
    }

    /**
     * register user
     *
     * @param $userData
     *
     * @return UserInterface
     * @throws \Exception
     */
    private function resolveUser($userData)
    {
        $handler = app('xe.user');
        $accountData = $userData['account'];

        // retrieve account and email
        $existingAccount = $handler->accounts()
            ->where(['provider' => $this->provider, 'account_id' => $accountData['account_id']])
            ->first();
        $existingEmail = array_get($accountData, 'email', false) ? $handler->emails()->findByAddress(
            $accountData['email']
        ) : null;

        $user = null;

        // when new user
        if ($existingAccount === null && $existingEmail === null) {
            return null;
        }

        XeDB::beginTransaction();
        try {
            if ($existingAccount !== null && $existingEmail === null) {
                // if email exists, insert email
                if ($accountData['email'] !== null) {
                    $existingEmail = $handler->createEmail(
                        $existingAccount->user,
                        ['address' => $accountData['email']]
                    );
                }
            } elseif ($existingAccount === null && $existingEmail !== null) {
                // if account is not exists, insert account
                $existingAccount = $handler->createAccount($existingEmail->user, $accountData);
            } elseif ($existingAccount !== null && $existingEmail !== null) {
                if ($existingAccount->user_id !== $existingEmail->user_id) {
                    $e = new XpressengineException();
                    $e->setMessage(xe_trans('social_login::alreadyRegisteredEmail'));
                    throw $e;
                }
            }

            // update token
            if ($existingAccount !== null && $existingAccount->token !== $accountData['token']) {
                $existingAccount->token = $accountData['token'];

                if(array_has($accountData, 'token_secret')) {
                    $existingAccount->token_secret = $accountData['token_secret'];
                }

                $existingAccount = $handler->updateAccount($existingAccount);
            }
        } catch (\Exception $e) {
            XeDB::rollback();
            throw $e;
        }
        XeDB::commit();

        // user exists, get existing user
        if ($user === null) {
            $user = $existingAccount->user;
        }

        return $user;
    }

    private function connectToUser($user, $userInfo)
    {
        $handler = app('xe.user');

        // retrieve account and email
        $existingAccount = $handler->accounts()
            ->where(['provider' => $this->provider, 'account_id' => $userInfo->id])
            ->first();

        $existingEmail = null;
        if (data_get($userInfo, 'email', false)) {
            $existingEmail = $handler->emails()->findByAddress($userInfo->email);
        }

        $id = $user->getId();

        if ($existingAccount !== null && $existingAccount->user_id !== $id) {
            $e = new XpressengineException();
            $e->setMessage(xe_trans('social_login::alreadyRegisteredAccount'));
            throw $e;
        }

        if ($existingEmail !== null && $existingEmail->user_id !== $id) {
            $e = new XpressengineException();
            $e->setMessage(xe_trans('social_login::alreadyRegisteredEmail'));
            throw $e;
        }

        $userData = $this->resolveUserInfo($userInfo);

        XeDB::beginTransaction();
        try {
            if ($existingAccount === null) {
                $existingAccount = $handler->createAccount($user, $userData['account']);
            }
            if ($existingEmail === null) {
                $existingEmail = $handler->createEmail($user, ['address' => $userData['email']]);
            }
        } catch (\Exception $e) {
            XeDB::rollback();
            throw $e;
        }
        XeDB::commit();
    }

    private function loginMember($user)
    {
        app('auth')->login($user);
    }

    /**
     * getConfig
     *
     * @param $provider
     *
     * @return mixed
     */
    protected function getConfig($provider)
    {
        return config('services.'.$provider);
    }

    public function resolveUserInfo($userInfo)
    {
        $accountInfo = $this->resolveAccountInfo($userInfo);
        $displayName = $this->resolveDisplayName($userInfo->nickname ?: $userInfo->name);
        return [
            'email' => $userInfo->email,
            'display_name' => $displayName,
            'account' => $accountInfo
        ];
    }

    private function resolveAccountInfo($userInfo)
    {
        return [
            'email' => $userInfo->email,
            'account_id' => $userInfo->id,
            'provider' => $this->provider,
            'token' => $userInfo->token,
            'token_secret' => isset($userInfo->token_secret) ? $userInfo->token_secret : ''
        ];
    }

    private function resolveDisplayName($displayName)
    {
        $handler = app('xe.user');

        $i = 0;
        $name = $displayName;
        while (true) {
            if ($handler->users()->where(['display_name' => $name])->first() !== null) {
                $name = $displayName.' '.$i++;
            } else {
                return $name;
            }
        }
    }
}
