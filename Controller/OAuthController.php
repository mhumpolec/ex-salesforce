<?php

namespace Keboola\SfdcExtractorBundle\Controller;

use Keboola\ExtractorBundle\Controller\OAuth20Controller;

class OAuthController extends OAuth20Controller
{
	/**
	 * @var string
	 */
	protected $appName = "ex-sfdc";

	/**
	 * OAuth 2.0 token retrieval URL
	 * See (C) at @link http://www.ibm.com/developerworks/library/x-androidfacebookapi/fig03.jpg
	 * ie: https://api.example.com/oauth2/token
	 * @var string
	 */
	protected $tokenUrl = "";

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
		// return "https://api.example.com/oauth/authorize/?client_id={$clientId}&redirect_uri={$redirUrl}&response_type=code&state={$hash}";
	}
}
