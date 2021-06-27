<?php

declare(strict_types=1);

namespace PezosSandbox\Infrastructure\Symfony\Controller;

use PezosSandbox\Application\ApplicationInterface;
use PezosSandbox\Application\Tokens\Token;
use PezosSandbox\Infrastructure\Symfony\Form\LoginForm;
use PezosSandbox\Infrastructure\Symfony\Form\TimeIntervalForm;
use PezosSandbox\Infrastructure\Tezos\Contract;
use PezosSandbox\Infrastructure\Tezos\Decimals;
use PezosSandbox\Infrastructure\Tezos\Storage\GetStorage;
use PezosSandbox\Infrastructure\Tezos\StorageHistory\GetStorageHistory;
use PezosSandbox\Infrastructure\UX\TokenChart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

final class IndexController extends AbstractController
{
    private ApplicationInterface $application;
    private SessionInterface $session;
    private GetStorageHistory $getStorageHistory;
    private GetStorage $getStorage;
    private TokenChart $tokenChart;

    public function __construct(
        ApplicationInterface $application,
        SessionInterface $session,
        GetStorageHistory $getStorageHistory,
        GetStorage $getStorage,
        TokenChart $tokenChart
    ) {
        $this->application       = $application;
        $this->session           = $session;
        $this->getStorageHistory = $getStorageHistory;
        $this->getStorage        = $getStorage;
        $this->tokenChart        = $tokenChart;
    }

    /**
     * @Route("/", name="index", methods={"GET"})
     */
    public function index(Request $request): Response
    {
        $loginForm        = $this->createForm(LoginForm::class);
        $timeIntervalForm = $this->createForm(TimeIntervalForm::class);
        $tokens           = $this->application->listTokens();

        $address = $request->query->get(
            'address',
            $tokens[0]->address()->asString()
        );
        $token  = $this->application->getOneTokenByAddress($address);
        $charts = $this->tokenChart->createCharts(
            $token,
            $this->application->getCurrentTime(),
            $this->session->get('time_interval')
        );
        $lastUpdates = $this->tokenChart->lastUpdates();
        $supply      = isset($token->metadata()['supply'])
            ? $token->metadata()['supply']
            : $this->getTokenSupplyFromStorage(
                $token->address()->contract(),
                $token->metadata()['decimals']
            );

        return $this->render('index.html.twig', [
            'charts'           => $charts,
            'counters'         => $this->getCounters($tokens),
            'lastUpdates'      => $lastUpdates,
            'loginForm'        => $loginForm->createView(),
            'timeIntervalForm' => $timeIntervalForm->createView(),
            'tokens'           => $tokens,
            'token'            => $token,
            'tokenLastUpdate'  => $this->getTokenLastUpdate($token),
            'supply'           => $supply,
        ]);
    }

    private function getTokenSupplyFromStorage(string $contract, int $decimals)
    {
        $supply = $this->getStorage
            ->getStorage(Contract::fromString($contract))
            ->totalSupply();

        return $supply / 10 ** $decimals;
    }

    private function getTokenLastUpdate(Token $token): array
    {
        $history = $this->getStorageHistory
            ->getStorageHistory(
                Contract::fromString($token->exchanges()[0]->contract()),
                Decimals::fromInt($token->metadata()['decimals'])
            )
            ->history($this->application->getCurrentTime());

        return end($history);
    }

    private function getCounters(array $tokens): array
    {
        return [
            'FA1.2' => array_reduce(
                $tokens,
                fn (int $count, Token $token): int => null ===
                $token->address()->id()
                    ? $count + 1
                    : $count,
                0
            ),
            'FA2' => array_reduce(
                $tokens,
                fn (int $count, Token $token): int => null !==
                $token->address()->id()
                    ? $count + 1
                    : $count,
                0
            ),
        ];
    }
}
