<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/logger', function () use ($getLoggerMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
		$user = $this->user;

        if ($user['tenant_id'] > 0)
        {
            $loggers_stmt = $this->db->query("SELECT DISTINCT
                    logger.sn
                FROM
                    logger
                WHERE
                    logger.tenant_id = {$user['tenant_id']}");
        }
        else
        {
            $loggers_stmt = $this->db->query("SELECT DISTINCT
                    logger.sn
                FROM
                    logger");
        }
        $logger_id = $loggers_stmt->fetchAll(PDO::FETCH_COLUMN);

        $loggers = [];
        foreach ($logger_id as $sn) {
            // $today = date('Y-m-d 00:00:00');
            $raw = $this->db->query("SELECT
                    content
                FROM
                    raw
                WHERE
                    content->>'device' similar to '%({$sn})%'
                ORDER BY
                    content->>'sampling' DESC
                LIMIT 1")
            ->fetch(PDO::FETCH_COLUMN);
            if ($raw) {
                $raw = json_decode($raw);
            }

            $loggers[] = [
                'sn' => substr($sn, 2),
                'content' => $raw
            ];
        }

        usort($loggers, function ($a, $b) {
            if (!$a['content'] || !$b['content']) {
                if ($a['content']) {
                    return -1;
                } else if ($b['content']) {
                    return 1;
                }

                return $a['sn'] > $b['sn'] ? 1 :
                    ($a['sn'] < $b['sn'] ? -1 : 0);
            }

            return $a['content']->sampling > $b['content']->sampling ? -1 :
                ($a['content']->sampling < $b['content']->sampling ? 1 : 0);
        });

        // $total_data = date('H') * 12 + floor(date('i') / 5);

        return $this->view->render($response, 'logger/index_mobile.html', [
            'loggers' => $loggers,
            // 'total_data' => $total_data,
        ]);
	});

    $this->get('/add', function (Request $request, Response $response, $args) {
        $user = $this->user;

        $logger = [
            'sn' => '',
            'location_id' => '',
            'tenant_id' => $user['tenant_id'] ?: intval($request->getParam('t', 0)),
        ];

        if ($user['tenant_id'] > 0) {
            $tenants = $this->db->query("SELECT * FROM tenant WHERE id={$user['tenant_id']} ORDER BY nama")->fetchAll();
        } else {
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
        }

        return $this->view->render($response, 'logger/edit.html', [
            'mode' => 'Add',
            'logger' => $logger,
            'tenants' => $tenants
        ]);
    });

    $this->post('/add', function (Request $request, Response $response, $args) {
        $logger = $request->getParams();

        $stmt = $this->db->prepare("INSERT INTO logger (sn, location_id, tenant_id) VALUES (:sn, :location_id, :tenant_id)");
        $stmt->bindValue(':sn', $logger['sn']);
        $stmt->bindValue(':location_id', $logger['location_id'] ? $logger['location_id'] : null);
        $stmt->bindValue(':tenant_id', $logger['tenant_id'] ? $logger['tenant_id'] : null);
        $stmt->execute();

        $this->flash->addMessage('messages', "Logger {$logger[sn]} telah ditambahkan");
        
        return $response->withRedirect('/logger');
    });

    $this->group('/{id}', function () {

        $this->get('', function (Request $request, Response $response) {
            return $response->withJson([
                'status' => 200,
                'message' => "OK"
            ]);
        });

        $this->get('/edit', function (Request $request, Response $response, $args) {
	        $logger = $request->getAttribute('logger');
	        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

	        return $this->view->render($response, 'logger/edit.html', [
	            'mode' => 'Edit',
	            'logger' => $logger,
	            'tenants' => $tenants
	        ]);
	    });

        $this->post('/edit', function (Request $request, Response $response, $args) {
	        $logger = $request->getAttribute('logger');
	        $logger['sn'] = $request->getParam('sn', $logger['sn']);
	        $logger['location_id'] = $request->getParam('location_id', $logger['location_id']);
	        $logger['tenant_id'] = $request->getParam('tenant_id', $logger['tenant_id']);

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE logger set sn=:sn, location_id=:location_id, tenant_id=:tenant_id, modified_at='$now' WHERE id=:id");
	        $stmt->bindValue(':sn', $logger['sn']);
	        $stmt->bindValue(':location_id', $logger['location_id'] ? $logger['location_id'] : null);
	        $stmt->bindValue(':tenant_id', $logger['tenant_id'] ? $logger['tenant_id'] : null);
	        $stmt->bindValue(':id', $logger['id']);
	        $stmt->execute();

	        $this->flash->addMessage('messages', "Perubahan Logger {$logger[sn]} telah disimpan");
	        
	        return $response->withRedirect('/logger');
	    });
    })->add($getLoggerMiddleware);
})->add($loggedinMiddleware);