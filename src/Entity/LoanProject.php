<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\OrganisationRepository")
 * @ORM\Table(name="loan_projects")
 */
class LoanProject
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    public $id;

    /**
     * @ORM\Column(type="integer")
     */
    public $organisation;

    /**
     * @ORM\Column(type="integer")
     */
    public $representative;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $alias;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $title;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $address;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $postal;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $city;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $stateProvince;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $country;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $notes;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getOrganisation()
    {
        return $this->organisation;
    }

    public function setOrganisation($organisation)
    {
        $this->organisation = $organisation;
    }

    public function getRepresentative()
    {
        return $this->representative;
    }

    public function setRepresentative($representative)
    {
        $this->representative = $representative;
    }

    public function getAlias()
    {
        return $this->alias;
    }

    public function setAlias($alias)
    {
        $this->alias = $alias;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function getAddress()
    {
        return $this->address;
    }

    public function setAddress($address)
    {
        $this->address = $address;
    }

    public function getPostal()
    {
        return $this->postal;
    }

    public function setPostal($postal)
    {
        $this->postal = $postal;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function setCity($city)
    {
        $this->city = $city;
    }

    public function getStateProvince()
    {
        return $this->stateProvince;
    }

    public function setStateProvince($stateProvince)
    {
        $this->stateProvince = $stateProvince;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function setCountry($country)
    {
        $this->country = $country;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }
}
