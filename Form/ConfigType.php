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
                    case 'bool':
                        $classType = 'CheckboxType';
                        break;
                    case 'int':
                        $classType = 'IntegerType';
                        break;
                    case 'float':
                        $classType = 'NumberType';
                        break;
                    case 'array':
                    case 'string':
                    default:
                        $classType = 'TextType';
                        break;
                }

                //Adds field
                $builder
                    ->add($key, '\Symfony\Component\Form\Extension\Core\Type\\' . $classType, array(
                        'label' => $key,
                        'label_attr' => array(
                            'title' => null !== $value['info'] ? $value['info'] : $key,
                        ),
                        'required' => $value['required'],
                        'data' => is_array($value['data']) ? json_encode($value['data']) : $value['data'],
                        'attr' => array(
                            'placeholder' => null !== $value['info'] ? $value['info'] : $key,
                            'title' => null !== $value['info'] ? $value['info'] : $key,
                        )))
                    ;
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