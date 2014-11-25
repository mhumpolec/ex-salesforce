<?php

namespace Keboola\SalesforceExtractorBundle\Controller;

use Keboola\Csv\CsvFile;
use Keboola\ExtractorBundle\Controller\ConfigsController as Controller,
    Keboola\Utils\Utils,
	Keboola\ExtractorBundle\Common\Exception;
use Keboola\StorageApi\ClientException;
use	Keboola\StorageApi\Table;
use Keboola\Temp\Temp;
use Keboola\Utils\Exception\JsonDecodeException;
use	Symfony\Component\HttpFoundation\Response;
use Syrup\ComponentBundle\Exception\UserException;

class ConfigsController extends Controller {
	protected $appName = "ex-salesforce";
	protected $columns = array (
      0 => 'load',
      1 => 'query',
    );

    /**
     *
     * attributes to be stored in Storage as protected
     *
     * @var array
     */
    protected $protectedAttributes = array(
        "oauth.refresh_token",
        "oauth.access_token",
        "oauth.password",
        "securityToken",
        "password",
    );

    /**
   	 * Save configuration
   	 * @param string $id Config table name
     * @param $id
     * @return Response
     * @throws UserException
     * @throws \Exception
     * @throws \Keboola\Csv\Exception
     * @throws \Keboola\StorageApi\ClientException
     * @throws \Keboola\Utils\Exception\JsonDecodeException
     * @return Response JSON response
     */
   	public function postConfigAction($id)
   	{
        if (!$this->storageApi->bucketExists($this->getBucketName())) {
            list($stage, $bucketName) = explode(".c-", $this->getBucketName());
            $this->storageApi->createBucket($bucketName, $stage, "{$this->appName} extractor configuration");
        }

        try {
            $body = Utils::json_decode($this->getRequest()->getContent(), true);
        } catch(JsonDecodeException $e) {
            throw new UserException("Error parsing JSON coniguration.", $e);
        }

        // Save table
        $temp = new Temp("{$this->appName}-config");
        $tmpFile = $temp->createTmpFile($id);
        $csvFile = new CsvFile($tmpFile->getPathName());
        $columns = array_merge($this->columns, array("rowId"));
        $csvFile->writeRow($columns);
        foreach($body["data"] as $row) {
            $rowData = array();
            foreach($columns as $column) {
                $rowData[] = $row[$column];
            }
            $csvFile->writeRow($rowData);
        }
        try {
            if ($this->storageApi->tableExists($this->getBucketName() . "." . $id)) {
                $this->storageApi->writeTableAsync($this->getBucketName() . "." . $id, $csvFile);
            } else {
                $this->storageApi->createTableAsync($this->getBucketName(), $id, $csvFile, array("primaryKey" => "rowId"));
            }
        } catch(ClientException $e) {
            throw new UserException($e->getMessage(), $e);
        }

        // Save attributes
        $flat = $this->flatten($body["attributes"]);
        foreach($flat as $key => $value) {
            $protected = false;
            if (in_array($key, $this->protectedAttributes)) {
                $protected = true;
            }
            $this->storageApi->setTableAttribute($this->getBucketName() . "." . $id, $key, $value, $protected);
        }

        return new Response(json_encode(["status" => "ok"]), 200, $this->defaultResponseHeaders);
   	}

    /**
     *
     * flattens JSON configuration structure to dot separated flat structure (for storing in Storage attributes)
     *
     * @param $item
     * @return mixed
     */
    protected function flatten($item) {
        if (!is_array($item)) {
            return $item;
        }
        $result = array();
        foreach ($item as $subitemkey => $subitem) {
            $flat = $this->flatten($subitem);
            if (is_array($flat)) {
                foreach($flat as $subflatkey => $subflat) {
                    $result[$subitemkey . "." . $subflatkey] = $subflat;
                }
            } else {
                $result[$subitemkey] = $flat;
            }
        }
        return $result;
    }

}
