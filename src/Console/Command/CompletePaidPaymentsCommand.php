<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Console\Command;

use Doctrine\Persistence\ObjectManager;
use Payum\Core\Model\GatewayConfigInterface;
use SM\Factory\FactoryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Abstraction\StateMachine\WinzouStateMachineAdapter;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\PayPalPlugin\Api\CacheAuthorizeClientApiInterface;
use Sylius\PayPalPlugin\Api\OrderDetailsApiInterface;
use Sylius\PayPalPlugin\DependencyInjection\SyliusPayPalExtension;
use Sylius\PayPalPlugin\Payum\Action\StatusAction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CompletePaidPaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly ObjectManager $paymentManager,
        private readonly CacheAuthorizeClientApiInterface $authorizeClientApi,
        private readonly OrderDetailsApiInterface $orderDetailsApi,
        private readonly FactoryInterface|StateMachineInterface $stateMachineFactory,
    ) {
        parent::__construct();

        if ($this->stateMachineFactory instanceof FactoryInterface) {
            trigger_deprecation(
                'sylius/paypal-plugin',
                '1.6',
                sprintf(
                    'Passing an instance of "%s" as the fifth argument is deprecated and will be prohibited in 2.0. Use "%s" instead.',
                    FactoryInterface::class,
                    StateMachineInterface::class,
                ),
            );
        }

        trigger_deprecation(
            'sylius/paypal-plugin',
            '1.7',
            'The fifth argument of the constructor in class "%s" is deprecated and will be renamed to "stateMachine" in 2.0.',
            self::class,
        );
    }

    protected function configure(): void
    {
        $this
            ->setName('sylius:pay-pal-plugin:complete-payments')
            ->setDescription('Completes payments for completed PayPal orders')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payments = $this->paymentRepository->findBy(['state' => PaymentInterface::STATE_PROCESSING]);
        /** @var PaymentInterface $payment */
        foreach ($payments as $payment) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = $payment->getMethod();
            /** @var GatewayConfigInterface $gatewayConfig */
            $gatewayConfig = $paymentMethod->getGatewayConfig();
            if ($gatewayConfig->getFactoryName() !== SyliusPayPalExtension::PAYPAL_FACTORY_NAME) {
                continue;
            }

            /** @var string $payPalOrderId */
            $payPalOrderId = $payment->getDetails()['paypal_order_id'];

            $token = $this->authorizeClientApi->authorize($paymentMethod);
            $details = $this->orderDetailsApi->get($token, $payPalOrderId);

            if ($details['status'] === 'COMPLETED') {
                $this->getStateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);

                $paymentDetails = $payment->getDetails();
                $paymentDetails['status'] = StatusAction::STATUS_COMPLETED;

                $payment->setDetails($paymentDetails);
            }
        }

        $this->paymentManager->flush();

        return Command::SUCCESS;
    }

    private function getStateMachine(): StateMachineInterface
    {
        if ($this->stateMachineFactory instanceof FactoryInterface) {
            return new WinzouStateMachineAdapter($this->stateMachineFactory);
        }

        return $this->stateMachineFactory;
    }
}

class_alias(CompletePaidPaymentsCommand::class, '\Sylius\PayPalPlugin\Command\CompletePaidPaymentsCommand');
