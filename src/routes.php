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
                        ->where('user.nip', $nip)
                        ->where('user.password', $password)
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
                        ->where('user.nip', $params['nip'])
                        ->where('user.password', md5($params['password']))
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

    $app->get('/me', function($request, $response, $args) use ($container) {
        $user = $request->getAttribute('user');

        $db = $container->get('db');
        $data = $db->table('user')
                    ->where('user.id', $user->id)
                    ->first();

        $data->division_name = '-';
        $data->departement_name = '-';
        $departement = $db->table('departement')
                            ->where('departement.id', $data->departement)
                            ->first();
        
        if (!empty($departement)) {
            $data->departement_name = $departement->name;

            $division = $db->table('division')
                                ->where('division.id', $departement->division)
                                ->select('division.name')
                                ->first();
        
            if (!empty($division)) {
                $data->division_name = $division->name;
            }
        }

        $output = [
            'data' => $data,
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    })->add(new ValidateUser($container));

    $app->get('/quiz', function($request, $response, $args) use ($container) {
        $user = $request->getAttribute('user');

        $proktor_code = '';

        $db = $container->get('db');
        $data = $db->table('user_quiz')
                    ->where('user_quiz.user', $user->id)
                    ->join('quiz', 'user_quiz.quiz', '=', 'quiz.id')
                    ->join('proktor', 'user_quiz.proktor', '=', 'proktor.id')
                    ->select('user_quiz.*', 'quiz.name as quiz_name', 'proktor.name as proktor_name')
                    ->get();

        for ($i = 0; $i < count($data); $i++) {
            $proktor = $db->table('proktor')
                            ->where('proktor.id', $data[$i]->proktor)
                            ->first();
            
            if (!empty($proktor)) {
                $proktor_code = $proktor->code;
            }
    
            $data[$i]->proktor_code = $proktor_code;
            $proktor_code = '';
        }


        $output = [
            'data' => $data,
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    })->add(new ValidateUser($container));

    $app->get('/quiz/{code}', function($request, $response, $args) use ($container) {
        $user = $request->getAttribute('user');

        $db = $container->get('db');
        $proktor = $db->table('proktor')
                        ->where('proktor.code', strtoupper($args['code']))
                        ->first();

        if (!empty($proktor)) {
            $data = $db->table('quiz')
                        ->where('quiz.id', $proktor->quiz)
                        ->join('proktor', 'quiz.id', '=', 'proktor.quiz')
                        ->select('quiz.*', 'proktor.name as proktor_name', 'proktor.user as proktor_user')
                        ->first();

            $count_total_quiz_data = 0;
            $total_quiz_data_decoded = json_decode($data->total_quiz_data);

            for ($i = 0; $i < count($total_quiz_data_decoded); $i++) {
                $count_total_quiz_data += $total_quiz_data_decoded[$i]->total;
            }

            $data->count_total_quiz_data = $count_total_quiz_data;

            $proktor_user = $db->table('user')
                                    ->where('user.id', $data->proktor_user)
                                    ->select('user.name')
                                    ->first();

            $data->proktor_user_name = $proktor_user->name;

            $output = [
                'data' => $data,
                'meta' => [
                    'http' => 200,
                ],
            ];
    
            return $response->withJson($output, 200);
        }


        $output = [
            'error' => [
                'message' => 'Detail assessment tidak ditemukan',
                'code' => 404,
            ]
        ];

        return $response->withJson($output, 404);
    })->add(new ValidateUser($container));

    $app->get('/quiz/{code}/data', function($request, $response, $args) use ($container) {
        $user = $request->getAttribute('user');

        $db = $container->get('db');
        $proktor = $db->table('proktor')
                        ->where('proktor.code', strtoupper($args['code']))
                        ->first();

        $user_quiz = $db->table('user_quiz')
                        ->where('user_quiz.proktor', $proktor->id)
                        ->where('user_quiz.user', $user->id)
                        ->first();
        
        $question_decoded = json_decode($user_quiz->question);
        $answer_decoded = json_decode($user_quiz->multiple_answer);

        $question_data = [];
        $answer_data = [];
        for ($i = 0; $i < count($question_decoded); $i++) {
            $quiz_data = $db->table('quiz_data')
                            ->where('quiz_data.id', $question_decoded[$i])
                            ->first();

            $answer_text = [];
            for ($x = 0; $x < count($answer_decoded[$i]); $x++) {
                array_push($answer_text, json_decode($quiz_data->multiple_answer)[$answer_decoded[$i][$x]]);
            }

            array_push($question_data, $quiz_data->question);
            array_push($answer_data, $answer_text);
        }

        $user_quiz->question_data = $question_data;
        $user_quiz->answer_data = $answer_data;

        $quiz = $db->table('quiz')
                        ->where('quiz.id', $user_quiz->quiz)
                        ->first();

        $user_quiz->quiz_data = $quiz;

        $output = [
            'data' => $user_quiz,
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    })->add(new ValidateUser($container));

    $app->post('/quiz/validate', function($request, $response, $args) use ($container) {
        $params = $request->getParsedBody();
        $user = $request->getAttribute('user');

        $message = 'Kode proktor tidak ditemukan';

        $db = $container->get('db');
        $proktor = $db->table('proktor')
                        ->where('proktor.code', strtoupper($params['code']))
                        ->first();

        if (!empty($proktor)) {
            $user_quiz = $db->table('user_quiz')
                            ->where('user_quiz.proktor', $proktor->id)
                            ->where(function ($query) {
                                $query->where('user_quiz.state', 'PENDING')
                                      ->orWhere('user_quiz.state', 'PROGRESS');
                            })
                            ->first();

            if (empty($user_quiz)) {
                $quiz = $db->table('quiz')
                            ->where('quiz.id', $proktor->quiz)
                            ->join('proktor', 'quiz.id', '=', 'proktor.quiz')
                            ->select('quiz.*', 'proktor.name as proktor_name')
                            ->first();
            } else {
                $message = 'Anda sudah pernah mengerjakan quiz dengan kode proktor ' . $params['code'];
            }
        }

        $output = [
            'data' => [
                'status' => !empty($quiz) ? 'valid' : 'invalid',
                'message' => $message,
            ],
            'meta' => [
                'http' => 200,
            ],
        ];

        return $response->withJson($output, 200);
    })->add(new ValidateUser($container));

    $app->post('/quiz/start', function($request, $response, $args) use ($container) {
        $params = $request->getParsedBody();
        $user = $request->getAttribute('user');

        $db = $container->get('db');
        $proktor = $db->table('proktor')
                        ->where('proktor.code', strtoupper($params['code']))
                        ->first();

        if (!empty($proktor)) {
            $questions = [];
            $answers = [];
            $chosen = [];

            $quiz = $db->table('quiz')
                        ->where('quiz.id', $proktor->quiz)
                        ->first();

            $total_quiz_data = json_decode($quiz->total_quiz_data);

            for ($i = 0; $i < count($total_quiz_data); $i++) {
                $quiz_data = $db->table('quiz_data')
                                ->where('quiz_data.quiz_category', $total_quiz_data[$i]->id)
                                ->limit($total_quiz_data[$i]->total)
                                ->orderByRaw('RAND()')
                                ->get();

                for ($x = 0; $x < count($quiz_data); $x++) {
                    $answer = [];
                    $answer_decoded = json_decode($quiz_data[$x]->multiple_answer);

                    for ($u = 0; $u < count($answer_decoded); $u++) {
                        array_push($answer, $u);
                    }

                    shuffle($answer);

                    array_push($questions, $quiz_data[$x]->id);
                    array_push($answers, $answer);
                    array_push($chosen, [
                        'index' => -1,
                        'flag' => false,
                    ]);
                }
            }

            $user_quiz_id = $db->table('user_quiz')
                            ->insertGetId([
                                'user' => $user->id,
                                'quiz' => $quiz->id,
                                'proktor' => $proktor->id,
                                'question' => json_encode($questions),
                                'multiple_answer' => json_encode($answers),
                                'chosen_answer' => json_encode($chosen),
                                'created_at' => time(),
                            ]);

            $user_quiz = $db->table('user_quiz')
                        ->where('user_quiz.id', $user_quiz_id)
                        ->first();

            $output = [
                'data' => $user_quiz,
                'meta' => [
                    'http' => 200,
                ],
            ];

            return $response->withJson($output, 200);
        }

        $output = [
            'error' => [
                'message' => 'Kode proktor tidak ditemukan',
                'code' => 404,
            ]
        ];

        return $response->withJson($output, 404);
    })->add(new ValidateUser($container));
};
