<?php

namespace Keboola\SalesforceExtractorBundle;

use Keboola\ExtractorBundle\Common\JobConfig;
use Keboola\ExtractorBundle\Extractor\Extractor as Extractor;
use Keboola\Json\Parser;
use Keboola\Utils\Exception\JsonDecodeException;
use Monolog\Registry;
use GuzzleHttp\Client as Client;
use Syrup\ComponentBundle\Exception\UserException;

class SalesforceExtractor extends Extractor
{
	protected $name = "salesforce";
    protected $loginUrl = "https://login.salesforce.com";
    protected $params = array();

    /**
     * @param $refreshToken
     * @return \GuzzleHttp\Message\ResponseInterface|mixed
     */
    protected function revalidateAccessToken($refreshToken)
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
        try {
            $response = \Keboola\Utils\Utils::json_decode($response->getBody(), false, 512, 0, true);
        } catch (JsonDecodeException $e) {
            $newE = new UserException("Cannot parse response from server when revalidating access token.", $e);
            $newE->setData($e->getData());
            throw $newE;
        }
        return $response;
    }

    /**
     * @param $params
     */
    public function __construct($params)
    {
        $this->params = $params;
    }

    /**
     * @param array $config
     */
    public function run($config)
    {
        $rowId = null;
        $params = $this->getSyrupJob()->getParams();
        if (isset($params["rowId"])) {
            $rowId = $params["rowId"];
        }

        $sfc = new \SforcePartnerClient();

        $loginRequired = false;
        foreach($config["data"] as $jobConfig) {
            if ($rowId && $jobConfig["rowId"] != $rowId) {
                continue;
            }
            if ($jobConfig["load"] == 'increments') {
                $loginRequired = true;
            }
        }
        if (
            isset($config["attributes"]["username"]) && $config["attributes"]["username"] != ''
            && isset($config["attributes"]["password"]) && $config["attributes"]["password"] != ''
            && isset($config["attributes"]["securityToken"]) && $config["attributes"]["securityToken"] != ''
            && $loginRequired
        ) {
            try {
                $sfc->createConnection(__DIR__ . "/Resources/sfdc/partner.wsdl.xml");
                $sfc->login($config["attributes"]["username"], $config["attributes"]["password"] . $config["attributes"]["securityToken"]);
            } catch (\SoapFault $e) {
                throw new UserException("Can't login into SalesForce: " . $e->getMessage(), $e);
            }
        }

        /**
         * @var $jobConfig JobConfig
         */
        foreach($config["jobs"] as $jobConfig) {
            if (!isset($config["attributes"]["oauth"]["refresh_token"])) {
                throw new UserException("SalesForce.com not authorized.");
            }
            $tokenInfo = $this->revalidateAccessToken($config["attributes"]["oauth"]["refresh_token"]);
            $client = new Client([
                "base_url" => $tokenInfo->instance_url,
                "defaults" => array(
                    "headers" => array(
                        "Authorization" => "OAuth {$tokenInfo->access_token}"
                    )
                )
            ]);

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
