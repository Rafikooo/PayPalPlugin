<?php

declare(strict_types=1);

namespace Sylius\PayPalPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

final class Version20241206094356 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Update factory name for PayPal gateway';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE sylius_gateway_config SET factory_name = 'sylius_paypal' WHERE factory_name = 'sylius.pay_pal'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE sylius_gateway_config SET factory_name = 'sylius.pay_pal' WHERE factory_name = 'sylius_paypal'");
    }
}
