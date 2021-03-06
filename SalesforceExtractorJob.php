<?php

namespace Keboola\SalesforceExtractorBundle;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use Keboola\CsvTable\Table;
use Keboola\ExtractorBundle\Common\JobConfig;
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
	protected function nextPage($response, $data)
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
     * @param mixed $response
     * @return mixed
     */
	protected function parse($response)
    {
        if (!$response) {
            throw new UserException("No response from API. SFDC may be warming up the cache, please try again later.");
        }
		/**
		 * Edit according to the parser used
		 */
        foreach($response->records as $key => $item) {
            $response->records[$key] = $this->removeAttributesFromResponse($item);
        }
        if (count($response->records) > 0) {
		    $this->parser->process($response->records, $this->getTableName());
        }
        return $response;
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
     * @param JobConfig $jobConfig
     * @param mixed $client
     * @param null $parser
     * @param \SforcePartnerClient $sfc
     */
    public function __construct(JobConfig $jobConfig, $client, $parser, \SforcePartnerClient $sfc)
    {
        $matches = array();
        preg_match('/FROM (\w*)/i', $jobConfig->getConfig()["query"], $matches);
        if (!isset($matches[1])) {
            throw new UserException("Malformed query: {$jobConfig->getConfig()["query"]}");
        }
        $outputTable = $matches[1];
        $this->setTableName($outputTable);


        $this->sfc = $sfc;
        parent::__construct($jobConfig, $client, $parser);

        $query = $this->config["query"];
        if ($this->config["load"] == 'increments') {
        // Incremental queries require SOQL modification
            if (stripos($query, "WHERE") !== false ) {
                $query .= " AND ";
            } else {
                $query .= " WHERE ";
            }
            // OpportunityFieldHistory and *History do not have SystemModstamp, only CreatedDate
            // OpportunityHistory does have SystemModstamp
            if (
                stripos($query, "OpportunityFieldHistory") !== false
                    || stripos($query, "History") !== false && stripos($query, "FieldHistory") === false && stripos($query, "History") > 0) {
                $query .= "CreatedDate > " . date("Y-m-d", strtotime("-1 week")) ."T00:00:00Z";
            } else {
                $query .= "SystemModstamp > " . date("Y-m-d", strtotime("-1 week")) ."T00:00:00Z";
            }
        }
        $this->config["query"] = $query;
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
            if ($this->config["load"] == 'increments') {
                $file->setIncremental(true);
            }

            // Primary key
            if (in_array("Id", $file->getHeader())) {
                $file->setPrimaryKey("Id");
            }
        }
        $this->files = $this->parser->getCsvFiles();

        // Add deleted files
        if ($this->config["load"] == 'increments') {
            if (!$this->sfc->getConnection()) {
                throw new UserException("Invalid Salesforce.com credentials.");
            }
            $deletedTableName = $this->getTableName() . "_deleted";
            $file = Table::create($deletedTableName, array("Id", "deletedDate"));
            $file->setPrimaryKey("Id");
            $file->setIncremental(true);
            $file->setName($deletedTableName);
            // cycle through single days
            for($i = 0; $i <= 14; $i++) {
                $dateFrom = date("Y-m-d", strtotime(-$i . " day")) . "T00:00:00Z";
                $dateTo = date("Y-m-d", strtotime(-$i + 1 . " day")) . "T00:00:00Z";
                try {
                    $records = $this->sfc->getDeleted($this->getTableName(), $dateFrom, $dateTo);
                } catch (\SoapFault $e) {
                    throw new UserException("Error retrieving deleted records: " . $e->getMessage(), $e);
                }
                $count = 0;
                if (isset($records->deletedRecords)) {
                    $deleted = $records->deletedRecords;
                    $count = count($deleted);
                    if ($deleted && count($deleted)) {
                        foreach($deleted as $deletedItem) {
                            $file->writeRow(array($deletedItem->id, $deletedItem->deletedDate));
                        }
                    }
                }
                Logger::log("info", "Retrieved {$count} deleted records for '" . $this->getTableName() . "' between {$dateFrom} and {$dateTo}.", array("config" => $this->config));
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
        } catch (RequestException $e) {
            // cUrl #52 error - salesforce may return empty response after a dropped connection during a long query
            if (strpos($e->getMessage(), "(#52)") !== false) {
                $message = "Connection with Salesforce was dropped.
                    It may be a connectivity issue, so please try again later.
                    If this happens regularly, you might be querying a large table in Salesforce and they drop the connection after 30 minutes.
                    To solve this issue limit your query to less results or extract the table incrementally.";
                throw new UserException($message, $e);
            }
        }
    }
}
