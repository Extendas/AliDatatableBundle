<?php

namespace Ali\DatatableBundle\Util\Factory\Prototype;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRendererInterface;

class PrototypeBuilder
{
    /** @var string */
    protected $_prototype;
    private FormFactoryInterface $form_factory;
    private FormRendererInterface $form_renderer;

    public function __construct(FormFactoryInterface $form_factory, FormRendererInterface $form_renderer, $type)
    {
        $this->form_factory = $form_factory;
        $method = "_{$type}";
        $rc = new \ReflectionClass(__CLASS__);
        if ($rc->hasMethod($method))
        {
            $this->_prototype = $this->$method();
        }
        else
        {
            throw new \Exception(sprintf('prototype "%s" not found', $type));
        }
        $this->form_renderer = $form_renderer;
    }
    
    /**
     * to string class converter
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->_prototype;
    }

    /**
     * simple form delete prototype
     * 
     * @return string
     */
    protected function _delete_form()
    {

        if (version_compare(phpversion(), '5.5', '<')) {
            $form = $this->form_factory->createBuilder('form', array('id' => '@id'), array())
                        ->add('id', 'hidden')
                        ->getForm();
        }
        else {
            $form = $this->form_factory->createBuilder('Symfony\Component\Form\Extension\Core\Type\FormType', array('id' => '@id'), array())
                        ->add('id', 'Symfony\Component\Form\Extension\Core\Type\HiddenType')
                        ->getForm();
        }

        return $this->form_renderer->searchAndRenderBlock($form->createView(), 'widget');
    }

}
