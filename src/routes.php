<?php

\Firebase\JWT\JWT::$leeway = 60;

use \Firebase\JWT\JWT;

class ValidateUser {
    private $container;

    public function __construct($container) {
        $this->container = $container;
    }

    public function __invoke($request, $response, $next)
    {
        $error = [
            'error' => 'Sesi telah berakhir, silahkan login lagi atau gunakan akun lain',
            'meta' => [
                'http' => 401,
            ],
        ];

        $query = $request->getQueryParams();

        $nip = '';
        $password = '';

        if (strlen($query['token'])) {
            $decoded_token = JWT::decode($query['token'], getenv('JWT_KEY'), array('HS256'));

            $nip = $decoded_token->nip;
            $password = $decoded_token->password;
        }

        if (!strlen($nip) || !strlen($password)) {
            return $response->withJson($error, 401);
        }

        $db = $this->container->get('db');
        $user = $db->table('user')
                        ->where('nip', $nip)
                        ->where('password', $password)
                        ->first();

        if (!$user) {
            return $response->withJson($error, 401);
        }

        $request = $request->withAttribute('user', $user);

        return $next($request, $response);
    }
}

return function($app) {
    $container = $app->getContainer();

    $app->post('/auth', function($request, $response, $args) use ($container) {
        $params = $request->getParsedBody();

        $db = $container->get('db');
        $user = $db->table('user')
                        ->where('nip', $params['nip'])
                        ->where('password', md5($params['password']))
                        ->first();

        $error = [
            'error' => 'Kombinasi NIP dan password salah',
            'meta' => [
                'http' => 401,
            ],
        ];

        if (!$user) {
            return $response->withJson($error, 401);
        }

        $payload->nip = $user->nip;
        $payload->password = $user->password;

        $output = [
            'data' => JWT::encode($payload, getenv('JWT_KEY')),
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    });

    $app->get('/quiz', function($request, $response, $args) use ($container) {
        $user = $request->getAttribute('user');

        $db = $container->get('db');
        $data = $db->table('user_quiz')
                    ->where('user', $user->id)
                    ->join('quiz', 'user_quiz.quiz', '=', 'quiz.id')
                    ->get();

        $output = [
            'data' => $data,
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    })->add(new ValidateUser($container));
};
