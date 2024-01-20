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
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        foreach ($options['data'] as $key => $value) {
            if ('configDataReserved' !== $key && is_array($value)) {
                $classesTypes = ['bool' => 'CheckboxType', 'date' => 'DateType', 'int' => 'IntegerType', 'float' => 'NumberType', 'array' => 'TextType', 'string' => 'TextType'];

                $classType = $classesTypes[$value['type']] ?? 'TextType';

                //Defines field options
                $fieldOptions = ['label' => $key, 'label_attr' => ['title' => $value['info'] ?? $key], 'required' => $value['required'], 'data' => is_array($value['data']) ? json_encode($value['data'], JSON_THROW_ON_ERROR) : $value['data'], 'attr' => ['placeholder' => $value['info'] ?? $key, 'title' => $value['info'] ?? $key]];

                //Defines specific data for date field
                if ('DateType' === $classType) {
                    $startYear = date('Y') - 5;
                    if (isset($value['startYear'])) {
                        if (is_int($value['startYear'])) {
                            $startYear = $value['startYear'];
                        } elseif ('current' === $value['startYear']) {
                            $startYear = date('Y');
                        }
                    }

                    $endYear = date('Y') + 5;
                    if (isset($value['endYear'])) {
                        if (is_int($value['endYear'])) {
                            $endYear = $value['endYear'];
                        } elseif ('current' === $value['endYear']) {
                            $endYear = date('Y');
                        }
                    }

                    $fieldOptions['years'] = range($startYear, $endYear);
                }

                //Adds field
                $builder->add($key, '\Symfony\Component\Form\Extension\Core\Type\\' . $classType, $fieldOptions);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => \c975L\ConfigBundle\Entity\Config::class, 'intention'  => 'configForm', 'translation_domain' => 'config']);
    }
}