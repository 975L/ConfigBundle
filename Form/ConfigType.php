<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Email ConfigType
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        foreach ($options['data'] as $key => $value) {
            if ('configDataReserved' !== $key) {
                switch ($value['type']) {
                    case 'BooleanNode':
                        $builder
                            ->add($key, '\Symfony\Component\Form\Extension\Core\Type\CheckboxType', array(
                                'label' => $key,
                                'required' => $value['required'],
                                'data' => $value['data'],
                                ));
                        break;
                    case 'IntegerNode':
                        $builder
                            ->add($key, '\Symfony\Component\Form\Extension\Core\Type\IntegerType', array(
                                'label' => $key,
                                'required' => $value['required'],
                                'data' => $value['data'],
                                'attr' => array(
                                    'placeholder' => null !== $value['info'] ? $value['info'] : $key,
                                )));
                        break;
                    case 'FloatNode':
                        $builder
                            ->add($key, '\Symfony\Component\Form\Extension\Core\Type\NumberType', array(
                                'label' => $key,
                                'required' => $value['required'],
                                'data' => $value['data'],
                                'attr' => array(
                                    'placeholder' => null !== $value['info'] ? $value['info'] : $key,
                                )));
                        break;
                    case 'ScalarNode':
                    default:
                        $builder
                            ->add($key, '\Symfony\Component\Form\Extension\Core\Type\TextType', array(
                                'label' => $key,
                                'required' => $value['required'],
                                'data' => is_array($value['data']) ? json_encode($value['data']) : $value['data'],
                                'attr' => array(
                                    'placeholder' => null !== $value['info'] ? $value['info'] : $key,
                                )));
                        break;
                }
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'c975L\ConfigBundle\Entity\Config',
            'intention'  => 'configForm',
            'translation_domain' => 'config',
        ));
    }
}