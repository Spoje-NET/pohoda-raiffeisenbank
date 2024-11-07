<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaRaiffeisenbank package
 *
 * https://github.com/Spoje-NET/pohoda-raiffeisenbank
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Test\Pohoda\RaiffeisenBank;

use Pohoda\RaiffeisenBank\PohodaBankClient;

/**
 * Generated by PHPUnit_SkeletonGenerator on 2024-11-07 at 14:32:00.
 */
class PohodaBankClientTest extends \PHPUnit\Framework\TestCase
{
    protected PohodaBankClient $object;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp(): void
    {
        $this->object = new \Pohoda\RaiffeisenBank\BankClientTester(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
    }

    /**
     * Tears down the fixture, for example, closes a network connection.
     * This method is called after a test is executed.
     */
    protected function tearDown(): void
    {
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::sourceString
     *
     * @todo   Implement testsourceString().
     */
    public function testsourceString(): void
    {
        $this->assertEquals('', $this->object->sourceString());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::checkCertificatePresence
     *
     * @todo   Implement testcheckCertificatePresence().
     */
    public function testcheckCertificatePresence(): void
    {
        $this->assertEquals('', $this->object->checkCertificatePresence());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::setScope
     *
     * @todo   Implement testsetScope().
     */
    public function testsetScope(): void
    {
        $this->assertEquals('', $this->object->setScope());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::getxRequestId
     *
     * @todo   Implement testgetxRequestId().
     */
    public function testgetxRequestId(): void
    {
        $this->assertEquals('', $this->object->getxRequestId());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::getCurrencyCode
     *
     * @todo   Implement testgetCurrencyCode().
     */
    public function testgetCurrencyCode(): void
    {
        $this->assertEquals('', $this->object->getCurrencyCode());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::checkForTransactionPresence
     *
     * @todo   Implement testcheckForTransactionPresence().
     */
    public function testcheckForTransactionPresence(): void
    {
        $this->assertEquals('', $this->object->checkForTransactionPresence());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::ensureKSExists
     *
     * @todo   Implement testensureKSExists().
     */
    public function testensureKSExists(): void
    {
        $this->assertEquals('', $this->object->ensureKSExists());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }

    /**
     * @covers \Pohoda\RaiffeisenBank\PohodaBankClient::insertTransactionToPohoda
     *
     * @todo   Implement testinsertTransactionToPohoda().
     */
    public function testinsertTransactionToPohoda(): void
    {
        $this->assertEquals('', $this->object->insertTransactionToPohoda());
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete('This test has not been implemented yet.');
    }
}
