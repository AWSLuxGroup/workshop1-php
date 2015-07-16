<?php

require __DIR__ . '/../vendor/autoload.php';


use Aws\S3\S3Client;
use Aws\Exception\S3Exception;
use Aws\Silex\AwsServiceProvider;
use Silex\Application;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;


// Setup the application
$app = new Application();
$app['debug'] = true;
$app['aws'] = new \Aws\Sdk([
    'region'  => 'eu-west-1',
    'version' => 'latest'
]);
$app['aws.s3'] = $app['aws']->createS3();
$app['aws.ddb'] = $app['aws']->createDynamoDb();
$app['env.orig'] = 'devday-source';
$app['env.trans'] = 'devday-thumbs';
$app['env.table'] = 'devday-items';
$app['imagine'] = new Imagine\Gd\Imagine();


$app->register(new TwigServiceProvider, array(
    'twig.path' => __DIR__ . '/template',
));


// Handle the index page
$app->match('/', function (Request $request) use ($app) {
    $alert = null;
        
    if ('POST' == $request->getMethod()) {
        $alert = handlePost($app, $request);
    }

	return $app['twig']->render('index.twig',
                 handleGet($app, $request, $alert));
});


// Handles HTTP GET methods -- Displays the list of items
function handleGet($app, $request, $alert) {
    $items = null;
    try {
        $items = $app['aws.ddb']->getIterator('Scan', [
          'TableName' => $app['env.table']
        ]);
    } catch (Exception $e) {
        $alert = array('type' => 'error',
                       'message' => "Table scan error:" . $e);
    }
    return array('alert' => $alert,
		   		'app' => $app,
                 'items' => $items);
}


// Handles HTTP POST requests -- Add the image to S3 + mata data to DynamoDB
function handlePost($app, $request) {    
    // Timestamp & reverse-timestamp
    $odt = new DateTime('now', new DateTimeZone("Europe/Amsterdam"));
    $timestamp = $odt->format("Y-m-d H:i");
    $rtimestamp = $odt->format("siHdmY");
    
    try {
		$uuid1 = Uuid::uuid1();
        // Make sure the media file was uploaded without error
        $file = $request->files->get('mediaFile');
        if (!$file instanceof UploadedFile || $file->getError()) {
            throw new \InvalidArgumentException('The file is not valid.');
        }
		$ofilename = $rtimestamp . '-' . $file->getFilename();

        // create a thumbnail
        $image = $app['imagine']->open($file->getPathname());
        $tfilename = $rtimestamp . '-' . $file->getFilename() . '-thumb.jpeg';
        $tfilepath = $file->getPath() . '/' . $tfilename;
        $image->resize(new Box(200, 200))->save($tfilepath);
        
        // Upload the original media file to S3
        $ores = $app['aws.s3']->upload($app['env.orig'], $ofilename,
                                       fopen($file->getPathname(), 'r'),
                                       'public-read');
        
        // upload the thumbnail
        $tres = $app['aws.s3']->upload($app['env.trans'], $tfilename,
                                       fopen($tfilepath, 'r'),
                                       'public-read');
        
        // add file metadata
        $dynamoDbResult = $app['aws.ddb']->putItem([
            'TableName' => $app['env.table'],
            'Item' => [
                'owner'   => ['S' => 'PHP-User'],
                'uid'   => ['S' => $uuid1->toString()],
                'timestamp' => ['S' => $timestamp],
                'description'   => ['S' => (String) $request->request->get('caption')],
                'name'  => ['S' => $file->getClientOriginalName()],
                'source'   => ['S' => $ofilename],
                'thumbnail'   => ['S' => $tfilename],
//                'source'   => ['S' => $ores['ObjectURL']],
//                'thumbnail'   => ['S' => $tres['ObjectURL']],
            ],
        ]);

        return array('type' => 'success',
                     'message' => 'File uploaded.');
    } catch (Exception $e) {
        // @TODO if something fails, rollback any object uploads
        return array('type' => 'error',
                     'message' => "Error uploading your photo:" . $e);
    }
}

$app->run();


