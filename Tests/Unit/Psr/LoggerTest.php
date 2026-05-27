<?php

declare(strict_types=1);

namespace Neos\Flow\Log\Tests\Unit\Psr;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Log\Backend\BackendInterface;
use Neos\Flow\Log\Psr\Logger;
use Neos\Flow\Tests\UnitTestCase;
use Psr\Log\LogLevel;

/**
 * Test case for PSR-3 based logger.
 */
final class LoggerTest extends UnitTestCase
{
    /**
     * @return \Iterator<(int | string), mixed>
     */
    public static function logLevelDataSource(): \Iterator
    {
        yield [LogLevel::EMERGENCY, LOG_EMERG, false];
        yield [LogLevel::DEBUG, LOG_DEBUG, false];
        yield [LogLevel::INFO, LOG_INFO, false];
        yield [LogLevel::NOTICE, LOG_NOTICE, false];
        yield [LogLevel::WARNING, LOG_WARNING, false];
        yield [LogLevel::ERROR, LOG_ERR, false];
        yield [LogLevel::CRITICAL, LOG_CRIT, false];
        yield [LogLevel::ALERT, LOG_ALERT, false];
        yield ['non existing loglevel', 'does not matter', true];
    }

    /**
     * @dataProvider logLevelDataSource
     * @test
     *
     * @param string $psrLogLevel
     * @param int $legacyLogLevel
     * @param bool $willError
     * @throws \ReflectionException
     */
    public function logAcceptsOnlyValidLogLevels($psrLogLevel, $legacyLogLevel, $willError): void
    {
        $mockBackend = $this->createMock(BackendInterface::class);
        if (!$willError) {
            $mockBackend->expects($this->once())->method('append')->with('some message', $legacyLogLevel);
        }
        $psrLogger = new Logger([$mockBackend]);

        try {
            $psrLogger->log($psrLogLevel, 'some message');
        } catch (\Throwable $throwable) {
            self::assertTrue($willError, $throwable->getMessage());
        }
    }

    /**
     * @dataProvider logLevelDataSource
     * @test
     *
     * @param string $psrLogLevel
     * @param int $legacyLogLevel
     * @param bool $willError
     * @throws \ReflectionException
     */
    public function levelSpecificMethodsAreSupported($psrLogLevel, $legacyLogLevel, $willError): void
    {
        $mockBackend = $this->createMock(BackendInterface::class);
        $mockBackend->expects($this->once())->method('append')->with('some message', $legacyLogLevel);

        $psrLogger = new Logger([$mockBackend]);

        if ($willError) {
            $this->markTestSkipped('unnecessary');
        }

        $psrLogger->$psrLogLevel('some message');
    }

    /**
     * @test
     */
    public function logSupportsContext(): void
    {
        $message = 'some message';
        $context = ['something' => 123, 'else' => true];
        $mockBackend = $this->createMock(BackendInterface::class);
        $mockBackend->expects($this->once())->method('append')->with('some message', LOG_INFO, $context);

        $psrLogger = new Logger([$mockBackend]);
        $psrLogger->log(LogLevel::INFO, $message, $context);
    }
}
