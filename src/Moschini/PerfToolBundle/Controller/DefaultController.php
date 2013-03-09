<?php
namespace Moschini\PerfToolBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Range;

// these import the "@Route" and "@Template" annotations
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use HarUtils\HarFile;
use HarUtils\HarTime;
use HarUtils\Url;

use DbUtils\SitesDb;

class DefaultController extends Controller
{

    private function getRowField($row, $field, $default = null)
    {
        return array_key_exists($field, $row) ? $row[$field] : $default;
    }
	/**
     * @Route("/")
     * @Route("/index")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $rows = SitesDb::getRecentRequestsList($request->get('site'), $request->get('url'));
        $requests = array();
        foreach($rows as $row)
        {
            $requests[ (string)$row['_id'] ] = array(
                'url' => $row['log']['entries'][0]['request']['url'],
                'date' => new HarTime($row['log']['pages'][0]['startedDateTime']),
                'agent' => $this->getRowField($row, 'agent'),
                );
        }
        return array(
            'requests' => $requests, 
        );
    }

	/**
     * @Route("/send")
     * @Template()
     */
    public function sendAction(Request $request)
    {
        $site = $request->get('site');
        $url = $request->get('url');
        $defaultData = array(
            'type' => 'har', 
            'url' => $url, 
            'site' => $site,
            'agent' => 'desktop',
            'nb' => 1);

        $form = $this->createFormBuilder($defaultData)
            //->add('type', 'choice', array('choices' => array('har' => 'har', 'loadtime' => 'loadtime')))
            ->add('site', 'text', array(
                'attr' => array(
                    'placeholder' => 'Site name',
                )
            ))
            ->add('url', 'text', array(
                'attr' => array(
                    'placeholder' => 'http://www.google.com',
                    'class' => 'input-xxlarge',
                )
            ))
            ->add('agent', 'choice', array('label' => 'User-Agent', 'choices' => array('desktop' => 'Desktop', 'mobile' => 'Mobile'), 'expanded' => true))
            ->add('nb', 'integer', array(
                'label' => 'Number of requests',
                'attr' => array(
                    'class' => 'input-mini',
                ),
                'constraints' => array(
                    new Range(array('min' => 1, 'max' => 20)),
                ),
            ))
            ->getForm();

        if($request->isMethod('POST'))
        {
            $form->bind($request);
            if($form->isValid())
            {
                $data = $form->getData();
                $msg = array(
                    'url' => $data['url'],
                    'site' => $data['site'],
                    'nb' => $data['nb'],
                    'account' => 'me',
                    'type' => 'har', //$data['type'],
                    'user-agent' => $data['agent'],
                );
                $this->get('old_sound_rabbit_mq.upload_picture_producer')->publish(json_encode($msg), 'perftest');
                // If no site has been defined, use the one used for this request 
                if(!$site){
                    $site = $data['site'];
                }
                return $this->redirect($this->generateUrl('moschini_perftool_default_done', array('site' => $site)));
            }
        }
        return array('form' => $form->createView());
        
    }
	
    /**
     * @Route("/done")
     * @Template()
     */
    public function doneAction()
    {
        return array();
    }
	
	/**
     * @Route("/graph")
     * @Template()
     */
    public function graphAction(Request $request)
	{
        $datas = SitesDb::getLoadTimesPerUrl($request->get('site'), $request->get('url'));
		return array(
			'datas' => $datas,
			);
    }

	/**
     * @Route("/time")
     * @Template()
     */
    public function timeAction(Request $request)
	{

        $datas = SitesDb::getLoadTimesAndDatePerUrl($request->get('site'), $request->get('url'));

        return array(
            'values' => $datas, 
        );
	}

    private function addOrderByAndDate($query, $field, $value, $sort = 1)
    {
        $operator = $sort == 1 ? '$gt' : '$lt';
        $query[$field] = array($operator => $value);
	    return array('query' => $query, 'orderby' => array($field => $sort));
    }

    private function getPreviousNext($item)
    {
        $db = SitesDb::getDb();
        $find = $this->getRelatedFinder($item);
        $date = $item['log']['pages'][0]['startedDateTime'];
        $findNext = $this->addOrderByAndDate($find, 'log.pages.startedDateTime', $date, 1);
        $findPrevious = $this->addOrderByAndDate($find, 'log.pages.startedDateTime', $date, -1);
        $next = $db->har->findOne($findNext, array('_id' => 1));
        $previous = $db->har->findOne($findPrevious, array('_id' => 1));
        return array($previous, $next);
    }

    private function getRelatedFinder($item)
    {
        $site = $item['site'];
        $url = $item['log']['entries'][0]['request']['url'];
        return array(
            '_id' => array('$ne' => $item['_id']),
            'site' => $site, 
            'log.entries.request.url' => $url
        );
    }

    private function getObjectId($item)
    {
        return $item ? $item['_id'] : null;
    }

    /**
     * @Route("/harviewer/{id}")
     * @Template()
     */
	public function harviewerAction(Request $request, $id)
    {
        $db = SitesDb::getDb();
        $mongoid = new \MongoId($id);
        $item = $db->har->findOne(array('_id' => $mongoid));
		$har = HarFile::fromJson($item);
        
        list($previous, $next) = $this->getPreviousNext($item);

        return array(
            'har' => $har,
            'previous' => $this->getObjectId($previous),
            'next' => $this->getObjectId($next),
        );
    }
}
