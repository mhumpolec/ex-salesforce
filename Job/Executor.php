<?php
/**
 * Created by Ondrej Vana <kachna@keboola.com>
 * Date: 17/09/14
 */

namespace Keboola\SfdcExtractorBundle\Job;

use Keboola\ExtractorBundle\Syrup\Job\Executor as ExExecutor;

class Executor extends ExExecutor
{
	protected $appName = "ex-sfdc";
}
