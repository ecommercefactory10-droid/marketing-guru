<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Middlewares\Cors;
use Neomerx\Cors\Strategies\Settings;

// Load environment
$envPath = dirname(__DIR__);
if (file_exists($envPath . '/.env')) {
    Dotenv::createImmutable($envPath)->safeLoad();
}

// Logger
$logger = new Logger('api');
$logPath = $envPath . '/storage/logs/app.log';
if (!is_dir(dirname($logPath))) {
    mkdir(dirname($logPath), 0777, true);
}
$logger->pushHandler(new StreamHandler($logPath, Level::Info));

// Database (SQLite by default)
$capsule = new Capsule();
$databasePath = $_ENV['DB_DATABASE'] ?? ($envPath . '/database/database.sqlite');
if (!is_dir(dirname($databasePath))) {
    mkdir(dirname($databasePath), 0777, true);
}
$capsule->addConnection([
    'driver' => $_ENV['DB_CONNECTION'] ?? 'sqlite',
    'database' => $databasePath,
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Run minimal dev-time migrations if tables do not exist yet
try {
    $schema = $capsule->schema();

    if (!$schema->hasTable('contacts')) {
        $schema->create('contacts', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
        });
    }

    if (!$schema->hasTable('campaigns')) {
        $schema->create('campaigns', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->enum('type', ['sms','newsletter'])->default('newsletter');
            $table->text('content')->nullable();
            $table->timestamps();
        });
    }

    if (!$schema->hasTable('events')) {
        $schema->create('events', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('name'); // e.g. cart_abandon, purchase, product_view
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
    }

    if (!$schema->hasTable('messages')) {
        $schema->create('messages', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('campaign_id')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->enum('channel', ['sms','email']);
            $table->enum('status', ['queued','sent','delivered','opened','clicked','failed'])->default('queued');
            $table->decimal('revenue', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    if (!$schema->hasTable('tasks')) {
        $schema->create('tasks', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['open','in_progress','done'])->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
    }
} catch (\Throwable $e) {
    $logger->error('Migration error: ' . $e->getMessage());
}

// App
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// CORS (allow local dev)
$origin = $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173';
$allowedOrigins = array_values(array_unique([$origin, 'http://localhost:5173']));
$corsSettings = (new Settings())
    ->setAllowedOrigins($allowedOrigins)
    ->setAllowedMethods(['GET','POST','PUT','PATCH','DELETE','OPTIONS'])
    ->setAllowedHeaders(['Content-Type','Authorization'])
    ->setCredentialsSupported();
$app->add(new Cors($corsSettings));

$errorMiddleware = $app->addErrorMiddleware((bool)($_ENV['APP_DEBUG'] ?? true), true, true);

// Routes
$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/metrics/summary', function (Request $request, Response $response) use ($capsule) {
    $totalContacts = $capsule->table('contacts')->count();
    $totalCampaigns = $capsule->table('campaigns')->count();
    $totalMessages = $capsule->table('messages')->count();
    $totalRevenue = (float) $capsule->table('messages')->sum('revenue');

    $payload = [
        'contacts' => $totalContacts,
        'campaigns' => $totalCampaigns,
        'messages' => $totalMessages,
        'revenue' => $totalRevenue,
    ];

    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/contacts', function (Request $request, Response $response) use ($capsule) {
    $contacts = $capsule->table('contacts')->orderBy('id','desc')->limit(200)->get();
    $response->getBody()->write($contacts->toJson());
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/contacts', function (Request $request, Response $response) use ($capsule) {
    $data = (array) $request->getParsedBody();
    $email = $data['email'] ?? null;
    if (!$email) {
        $response->getBody()->write(json_encode(['error' => 'email is required']));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }
    $now = date('Y-m-d H:i:s');
    $id = $capsule->table('contacts')->insertGetId([
        'email' => $email,
        'phone' => $data['phone'] ?? null,
        'first_name' => $data['first_name'] ?? null,
        'last_name' => $data['last_name'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $created = $capsule->table('contacts')->where('id',$id)->first();
    $response->getBody()->write(json_encode($created));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/campaigns', function (Request $request, Response $response) use ($capsule) {
    $campaigns = $capsule->table('campaigns')->orderBy('id','desc')->limit(200)->get();
    $response->getBody()->write($campaigns->toJson());
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/campaigns', function (Request $request, Response $response) use ($capsule) {
    $data = (array) $request->getParsedBody();
    $name = $data['name'] ?? null;
    if (!$name) {
        $response->getBody()->write(json_encode(['error' => 'name is required']));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }
    $now = date('Y-m-d H:i:s');
    $id = $capsule->table('campaigns')->insertGetId([
        'name' => $name,
        'type' => in_array(($data['type'] ?? 'newsletter'), ['sms','newsletter']) ? $data['type'] : 'newsletter',
        'content' => $data['content'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $created = $capsule->table('campaigns')->where('id',$id)->first();
    $response->getBody()->write(json_encode($created));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/events', function (Request $request, Response $response) use ($capsule) {
    $events = $capsule->table('events')->orderBy('id','desc')->limit(200)->get();
    $response->getBody()->write($events->toJson());
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/events', function (Request $request, Response $response) use ($capsule) {
    $data = (array) $request->getParsedBody();
    $name = $data['name'] ?? null;
    if (!$name) {
        $response->getBody()->write(json_encode(['error' => 'name is required']));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }
    $now = date('Y-m-d H:i:s');
    $id = $capsule->table('events')->insertGetId([
        'contact_id' => $data['contact_id'] ?? null,
        'name' => $name,
        'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        'occurred_at' => $data['occurred_at'] ?? $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $created = $capsule->table('events')->where('id',$id)->first();
    $response->getBody()->write(json_encode($created));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();