<?php

namespace Tests\Feature;

use app\example\traits\MNSClientTraits;

/**
 * @internal
 * @coversNothing
 */
final class MnsClientTest extends \PHPUnit\Framework\TestCase
{
    use MNSClientTraits;

    public function testQueueSend()
    {
        $params = [
            't' => time(),
        ];
        $flag = $this->sendToQueue('test', $params);
        $this->assertNotFalse(true);
    }

    public function testQueueBatchSend()
    {
        for ($i = 0; $i <= 100; $i++) {

            $params = [
                'i' => $i,
                't' => microtime(true),
            ];
            $flag = $this->sendToQueue('test', $params);
        }
        $this->assertTrue(true);
    }
}
