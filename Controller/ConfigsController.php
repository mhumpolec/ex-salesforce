<?php

namespace Keboola\SfdcExtractorBundle\Controller;

use Keboola\ExtractorBundle\Controller\ConfigsController as Controller,
	Keboola\ExtractorBundle\Common\Utils,
	Keboola\ExtractorBundle\Common\Exception;
use	Keboola\StorageApi\Table;
use	Symfony\Component\HttpFoundation\Response;

class ConfigsController extends Controller {
	protected $appName = "ex-sfdc";
	protected $columns = array (
  0 => 'load',
  1 => 'query',
);
}
