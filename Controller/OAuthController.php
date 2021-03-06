<?php

namespace Keboola\SalesforceExtractorBundle\Controller;

use Keboola\ExtractorBundle\Controller\OAuth20Controller;

class OAuthController extends OAuth20Controller
{
	/**
	 * @var string
	 */
	protected $appName = "ex-salesforce";

	/**
	 * OAuth 2.0 token retrieval URL
	 * See (C) at @link http://www.ibm.com/developerworks/library/x-androidfacebookapi/fig03.jpg
	 * ie: https://api.example.com/oauth2/token
	 * @var string
	 */
	protected $tokenUrl = "https://login.salesforce.com/services/oauth2/token";

	/**
	 * Create OAuth 2.0 request code URL (use CODE "response type")
	 * See (A) at @link http://www.ibm.com/developerworks/library/x-androidfacebookapi/fig03.jpg
	 * @param string $redirUrl Redirect URL
	 * @param string $clientId Application's registered Client ID
	 * @param string $hash Session verification code (use in the "state" query parameter)
	 * @return string URL
	 * ie: return "https://api.example.com/oauth2/auth?
	 *	response_type=code
	 *	&client_id={$clientId}
	 *	&redirect_uri={$redirUrl}
	 *	&scope=users.read+records.write
	 *	&state={$hash}"
	 *	(obviously without them newlines!)
	 */
	protected function getOAuthUrl($redirUrl, $clientId, $hash)
	{
		return "https://login.salesforce.com/services/oauth2/authorize?client_id={$clientId}&redirect_uri=" . urlencode($redirUrl) . "&response_type=code&state={$hash}&scope=api refresh_token web";
	}
}
