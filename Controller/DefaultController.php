<?php

namespace Keboola\SalesforceExtractorBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('KeboolaSalesforceExtractorBundle:Default:index.html.twig', array('name' => $name));
    }
}
