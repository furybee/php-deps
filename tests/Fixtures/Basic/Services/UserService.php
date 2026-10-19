<?php

namespace Acme\Services;

use Acme\Contracts\Repository;
use Acme\Models\User;
use Acme\Models\{HasProfile};

final class UserService extends BaseService
{
    public function __construct(
        private readonly Repository $repository,
        private ?Mailer $mailer = null,
    ) {
    }

    public function handle(User $user, $legacy): void
    {
        parent::boot();
        $this->repository->find(1);
        $this->mailer?->send();
        $user->profile();
        $user->notify();
        User::query();
        $user->refresh()->notify();
        $user->mailer->send();

        $mailer = app(Mailer::class);
        $mailer->send();
        resolve(Mailer::class);

        $handler = new class {
            public function handle(): void
            {
            }
        };
        $this->afterAnonymousClass();

        /** @var Mailer $fromContainer */
        $fromContainer = $legacy->get('mailer');
        $fromContainer->send();

        throw new \RuntimeException('global classes are not part of the graph');
    }

    /**
     * @param User $user
     */
    public function documented($user): ?User
    {
        $user->notify();
        $clone = clone $user;
        $clone->profile();

        return null;
    }

    public function afterAnonymousClass(): void
    {
        $this->documented(new User());
    }
}
