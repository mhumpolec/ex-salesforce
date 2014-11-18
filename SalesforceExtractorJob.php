<?php

namespace Keboola\SalesforceExtractorBundle;

use GuzzleHttp\Exception\ClientException;
use Keboola\CsvTable\Table;
use Keboola\ExtractorBundle\Extractor\Jobs\JsonJob as ExtractorJob,
	Keboola\ExtractorBundle\Common\Utils;
use Syrup\ComponentBundle\Exception\SyrupComponentException as Exception;
use Keboola\ExtractorBundle\Common\Logger;
use Syrup\ComponentBundle\Exception\UserException;

class SalesforceExtractorJob extends ExtractorJob
{

    /**
     * @var
     */
    protected $tableName;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * @var \Keboola\CsvTable\Table[]
     */
    protected $files = array();

    /**
     * @var string
     */
    private $apiUrl = "/services/data/v31.0/query?q=";

    /**
     * @var \SforcePartnerClient
     */
    private $sfc;

	/**
	 * @brief Return a download request
	 *
	 * @return \Keboola\ExtractorBundle\Client\SoapRequest | \GuzzleHttp\Message\Request
	 */
	protected function firstPage()
    {
		$url = $this->apiUrl . urlencode($this->config["query"]);
		$request = $this->client->createRequest("GET", $url);
        return $request;
	}

	/**
	 * @brief Return a download request OR false if no next page exists
	 *
	 * @param $response
	 * @return \Keboola\ExtractorBundle\Client\SoapRequest | \GuzzleHttp\Message\Request | false
	 */
	protected function nextPage($response)
    {
		if ($response->done) {
			return false;
		}
		return $this->client->createRequest("GET", $response->nextRecordsUrl);
	}

	/**
	 * @brief Call the parser and handle its return value
	 * - Wsdl and Json parsers results should be accessed by Parser::getCsvFiles()
	 * - JsonMap parser data should be saved to a CsvFile, OR a CsvFile must be provided as a second parameter to parser
	 * - JsonMap accepts a single row to parse()
	 * - Json::process(), Json::parse() (OBSOLETE) and Wsdl::parse() accept complete datasets (a full page)
	 *
	 * @param object $response
	 */
	protected function parse($response)
    {
		/**
		 * Edit according to the parser used
		 */
        foreach($response->records as $key => $item) {
            $response->records[$key] = $this->removeAttributesFromResponse($item);
        }
        if (count($response->records) > 0) {
		    $this->parser->process($response->records, $this->getTableName());
        }
	}

    /**
     *
     * Remove all 'attribute' attributes from response
     *
     * @param $obj
     */
    private function removeAttributesFromResponse($obj)
    {
        if (isset($obj->attributes)) {
            unset($obj->attributes);
        }
        foreach(get_object_vars($obj) as $key => $item) {
            if (is_object($item)) {
                $obj->$key = $this->removeAttributesFromResponse($obj->$key);
            }
        }
        return $obj;
    }

    /**
     * @return mixed
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * @param string $tableName
     */
    public function setTableName($tableName="")
    {
        $this->tableName = $tableName;
    }

    /**
     * @param array $jobConfig
     * @param $client
     * @param null $parser
     * @param \SforcePartnerClient $sfc
     */
    public function __construct($jobConfig, $client, $parser, \SforcePartnerClient $sfc)
    {
        $matches = array();
        preg_match('/FROM (\w*)/', $jobConfig["query"], $matches);
        $outputTable = $matches[1];
        $this->setTableName($outputTable);

        $query = $jobConfig["query"];
        if ($jobConfig["load"] == 'incremental') {
        // Incremental queries require SOQL modification
            if (strpos($query, "WHERE") !== false ) {
                $query .= " AND ";
            } else {
                $query .= " WHERE ";
            }
            // OpportunityFieldHistory and *History do not have SystemModstamp, only CreatedDate
            // OpportunityHistory does have SystemModstamp
            if (
                strpos($query, "OpportunityFieldHistory") !== false
                    || strpos($query, "History") !== false && strpos($query, "FieldHistory") === false && strpos($query, "History") > 0) {
                $query .= "CreatedDate > " . date("Y-m-d", strtotime("-1 week")) ."T00:00:00Z";
            } else {
                $query .= "SystemModstamp > " . date("Y-m-d", strtotime("-1 week")) ."T00:00:00Z";
            }
        }
        $jobConfig["query"] = $query;

        $this->sfc = $sfc;

        parent::__construct($jobConfig, $client, $parser);
    }

    /**
     * @throws \Syrup\ComponentBundle\Exception\UserException
     */
    public function run()
    {
        Logger::log("info", "Running query '" . $this->config["query"] . "'", array("config" => $this->config));
        parent::run();

        /** @var \Keboola\CsvTable\Table $file */
        foreach($this->parser->getCsvFiles() as $file)
        {
            // Incremental
            if ($this->config["load"] == 'incremental') {
                $file->setIncremental(true);
            }

            // Primary key
            if (in_array("Id", $file->getHeader())) {
                $file->setPrimaryKey("Id");
            }
        }
        $this->files = $this->parser->getCsvFiles();

        // Add deleted files
        if ($this->config["load"] == 'incremental') {
            if (!$this->sfc->getConnection()) {
                throw new UserException("Invalid Salesforce.com credentials.");
            }
            $deletedTableName = $this->getTableName() . "_deleted";
            $file = Table::create($deletedTableName, array("Id", "deletedDate"));
            $file->setPrimaryKey("Id");
            $file->setIncremental(true);
            $file->setName($deletedTableName);
            try {
                $records = $this->sfc->getDeleted($this->getTableName(), date("Y-m-d", strtotime("-29 day")) . "T00:00:00Z", date("Y-m-d", strtotime("+1 day")) . "T00:00:00Z");
            } catch (\SoapFault $e) {
                throw new UserException("Error retrieving deleted records: " . $e->getMessage());
            }
            if (isset($records->deletedRecords)) {
                $deleted = $records->deletedRecords;
                if ($deleted && count($deleted)) {
                    foreach($deleted as $deletedItem) {
                        $file->writeRow(array($deletedItem->id, $deletedItem->deletedDate));
                    }
                }
            }
            $this->files[$deletedTableName] = $file;
        }
        Logger::log("info", "Query finished", array("config" => $this->config));
    }

    /**
     * @return array
     */
    public function getCsvFiles()
    {
        return $this->files;
    }

    /**
     * @param \GuzzleHttp\Message\Request $request
     * @return mixed|object
     * @throws \Syrup\ComponentBundle\Exception\UserException
     * @throws \Exception
     * @throws SyrupComponentException
     */
    protected function download($request)
    {
        try {
            $response = parent::download($request);
            return $response;
        } catch (\Syrup\ComponentBundle\Exception\SyrupComponentException $e) {
            if ($e->getPrevious() && $e->getPrevious() instanceof ClientException && $e->getPrevious()->getResponse()->getStatusCode() == '400') {
                /* @var ClientException $prev */
                $prev = $e->getPrevious();
                $response = json_decode($prev->getResponse()->getBody());
                $userE = new UserException("Error downloading data from Salesforce:\n" . $response[0]->message, $e);
                $userE->setData(array("response" => $response[0]));
                throw $userE;
            }
            throw $e;
        }
    }
}
