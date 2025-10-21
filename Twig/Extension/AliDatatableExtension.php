<?php

namespace Ali\DatatableBundle\Twig\Extension;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Ali\DatatableBundle\Util\Datatable;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AliDatatableExtension extends AbstractExtension
{
    private FormFactoryInterface $form_factory;
    private Environment $twig;
    private RequestStack $request_stack;
    private TranslatorInterface $translator;
    private KernelInterface $kernel;

    public function __construct(FormFactoryInterface $form_factory, Environment $twig, RequestStack $request_stack, TranslatorInterface $translator, KernelInterface $kernel)
    {
        $this->form_factory = $form_factory;
        $this->twig = $twig;
        $this->request_stack = $request_stack;
        $this->translator = $translator;
        $this->kernel = $kernel;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('datatable', [$this, 'datatable'], ["is_safe" => ["html"]])
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('dta_trans', [$this, 'dtatransFilter'])
        ];
    }

    /**
     * Datatable translate filter
     * 
     * @param string $id
     * 
     * @return string
     */
    public function dtatransFilter($id)
    {
        $translator = $this->translator;
        $callback   = function($id) {
            $path = $this->kernel->locateResource('@AliDatatableBundle/Resources/translations/messages.en.yml');
            return \Symfony\Component\Yaml\Yaml::parse(file_get_contents($path))['ali']['common'][explode('.', $id)[2]];
        };
        return $translator->trans($id) === $id ? $callback($id) : $translator->trans($id);
    }

    /**
     * Converts a string to time
     * 
     * @param string $string
     * @return int 
     */
    public function datatable($options)
    {
        if (!isset($options['id']))
        {
            $options['id'] = 'ali-dta_' . md5(rand(1, 100));
        }
        $dt                       = Datatable::getInstance($options['id']);
        $config                   = $dt->getConfiguration();
        $options['js_conf']       = json_encode($config['js']);
        $options['js']            = json_encode($options['js']);
        $options['action']        = $dt->getHasAction();
        $options['action_twig']   = $dt->getHasRendererAction();
        $options['fields']        = $dt->getFields();
        $options['delete_form']   = $this->createDeleteForm('_id_')->createView();
        $options['search']        = $dt->getSearch();
        $options['search_fields'] = $dt->getSearchFields();
        $options['filter_fields'] = $dt->getFilterFields();
        $options['multiple']      = $dt->getMultiple();
        $options['sort']          = is_null($dt->getOrderField()) ? NULL : array(array_search(
                    $dt->getOrderField(), array_values($dt->getFields())), $dt->getOrderType());
        $main_template            = '@AliDatatable/Main/index.html.twig';
        if (isset($options['main_template']))
        {
            $main_template = $options['main_template'];
        }
        $session                  = $this->request_stack->getSession();
        $rawjs                    = $this->twig->render('@AliDatatable/Internal/script.html.twig', $options);
        $sess_dtb                 = $session->get('datatable', array());
        $sess_dtb[$options['id']] = $rawjs;
        $session->set('datatable', $sess_dtb);

        return $this->twig->render($main_template, $options);
    }

    private function createDeleteForm($id)
    {
        return $this->createFormBuilder(['id' => $id])
            ->add('id', 'Symfony\Component\Form\Extension\Core\Type\HiddenType')
            ->getForm();
    }

    public function createFormBuilder($data = null, array $options = array())
    {
        return $this->form_factory->createBuilder('Symfony\Component\Form\Extension\Core\Type\FormType', $data, $options);
    }

    /**
     * Returns the name of the extension.
     *
     * @return string The extension name
     */
    public function getName()
    {
        return 'DatatableBundle';
    }

}
