<?php

namespace GeneaLabs\LaravelSocialiter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;

class Socialiter
{
    public static $runsMigrations = true;

    protected $driver;
    protected $apitoken;

    public static function ignoreMigrations()
    {
        static::$runsMigrations = false;
    }

    public function driver(string $driver) : self
    {
        $this->driver = $driver;

        return $this;
    }

    public function login() : Model
    {
        $socialiteUser = Socialite::driver($this->driver)
            ->user();
        $user = $this->getUser($socialiteUser, $this->driver);
        $user->load("socialCredentials");

        auth()->login($user);

        return $user;
    }

    public function apilogin($socialiteUser, $apitoken) : Model
    {
        $this -> apitoken = $apitoken;
        $socialiteUser -> apitoken = $apitoken;
        $user = $this->getUser($socialiteUser, $this->driver);
        $user->load("socialCredentials");

        auth()->login($user);

        return $user;
    }

    protected function getUser(AbstractUser $socialiteUser) : Model
    {
        $user = $this->createCredentials($socialiteUser);

        return $user->user;
    }

    protected function createUser(AbstractUser $socialiteUser) : Model
    {
        $userClass = config("auth.providers.users.model");

        $user = User::where([
            ['email', '=', $socialiteUser->getEmail()],
            ['api_token', '=', $this->apitoken]
        ])->first();

        if (!$user) {
            $user = (new $userClass)
                ->firstOrCreate([
                    "email" => $socialiteUser->getEmail(),
                    "api_token" => $this->apitoken,
                    "name" => $socialiteUser->getName(),
                    "password" => Str::random(64),
                ]);
        } else {
            //$user->api_token = $this->apitoken;
            $user->name = $socialiteUser->getName();
        }

        $user->save();

        return $user;
    }

    public function createCredentials(AbstractUser $socialiteUser) : SocialCredentials
    {
        if(!isset($socialiteUser->email)){
            $socialiteUser->email = $socialiteUser -> apitoken;
        }

        $socialiteCredentials = (new SocialCredentials)
            ->with("user")
            ->firstOrNew([
                "provider_id" => $socialiteUser->getId(),
                "provider_name" => $this->driver,
            ])
            ->fill([
                "access_token" => $socialiteUser->token,
                "avatar" => $socialiteUser->getAvatar(),
                "email" => $socialiteUser->getEmail(),
                "expires_at" => (new Carbon)->now()->addSeconds($socialiteUser->expiresIn),
                "name" => $socialiteUser->getName(),
                "nickname" => $socialiteUser->getNickname(),
                "provider_id" => $socialiteUser->getId(),
                "provider_name" => $this->driver,
                "refresh_token" => $socialiteUser->refreshToken,
            ]);

        if (!$socialiteCredentials->exists) {
            $user = User::where(function($q) use ($socialiteUser) {
                $q->where('email','=', $socialiteUser->email)
                    ->orWhere('api_token', '=', $socialiteUser->apitoken);
            })->first();
            if (!$user) {
                $user = $this->createUser($socialiteUser);
            }

            $socialiteCredentials->user()->associate($user);
        }

        $socialiteCredentials->save();

        return $socialiteCredentials;
    }
}
