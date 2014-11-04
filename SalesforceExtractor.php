<?php

namespace Keboola\SalesforceExtractorBundle;

use Keboola\ExtractorBundle\Extractor\Extractor as Extractor;
use Keboola\Json\Parser;
use Monolog\Registry;
use Syrup\ComponentBundle\Exception\SyrupComponentException as Exception;
use GuzzleHttp\Client as Client;
use Keboola\SalesforceExtractorBundle\SalesforceExtractorJob;
use Syrup\ComponentBundle\Exception\UserException;

class SalesforceExtractor extends Extractor
{
	protected $name = "sfdc";
    protected $loginUrl = "https://login.salesforce.com";
    protected $params = array();

    /**
     * @param $accessToken
     * @param $refreshToken
     * @return \GuzzleHttp\Message\ResponseInterface|mixed
     */
    protected function revalidateAccessToken($accessToken, $refreshToken)
    {
        $client = new Client(
                   [
                       "base_url" => $this->loginUrl
                   ]
               );
        $url = "/services/oauth2/token";
        $response = $client->post($url, array(
           "body" => array(
               "grant_type" => "refresh_token",
               "client_id" => $this->params["client-id"],
               "client_secret" => $this->params["client-secret"],
               "refresh_token" => $refreshToken
           )
        ));
        $response = \Keboola\Utils\Utils::json_decode($response->getBody());
        return $response;
    }

    public function __construct($params)
    {
        $this->params = $params;
    }

    /**
     * @param array $config
     */
    public function run($config)
    {
        $sfc = new \SforcePartnerClient();
        if (
            isset($config["attributes"]["username"]) && $config["attributes"]["username"] != ''
            && isset($config["attributes"]["passSecret"]) && $config["attributes"]["username"] != ''
        ) {
            try {
                $sfc->createConnection(__DIR__ . "/Resources/sfdc/partner.wsdl.xml");
                $sfc->login($config["attributes"]["username"], $config["attributes"]["passSecret"]);
            } catch (\SoapFault $e) {
                throw new UserException("Can't login into SalesForce: " . $e->getMessage(), $e);
            }
        }
		foreach($config["data"] as $jobConfig) {


            $tokenInfo = $this->revalidateAccessToken($config["attributes"]["oauth"]["access_token"], $config["attributes"]["oauth"]["refresh_token"]);
            $client = new Client([
                "base_url" => $tokenInfo->instance_url
            ]);
            $client->setDefaultOption("headers", array(
                    "Authorization" => "OAuth {$tokenInfo->access_token}"
                )
            );

			// $this->parser is, by default, only pre-created when using JsonExtractor
			// Otherwise it must be created like Above example, OR withing the job itself
            $parser = new Parser(Registry::getInstance('extractor'), array(), 1);
			$job = new SalesforceExtractorJob($jobConfig, $client, $parser, $sfc);

			$job->run();
            $this->sapiUpload($job->getCsvFiles());
            $this->storageApi->setBucketAttribute("sys.c-" . $this->getFullName() . "." . $this->configName, "oauth.access_token", $tokenInfo->access_token);
            unset($client);
            unset($parser);
		}
	}
}
