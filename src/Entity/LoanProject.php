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
     * @ORM\Column(type="string", length=255)
     */
    public $alias;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $title;

    /**
     * @ORM\Column(type="integer")
     */
    public $organisation;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $organisationName;

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
    public $url;

    /**
     * @ORM\Column(type="datetime")
     */
    public $startDate;

    /**
     * @ORM\Column(type="datetime")
     */
    public $endDate;

    /**
     * @ORM\Column(type="datetime")
     */
    public $startDateInsured;

    /**
     * @ORM\Column(type="datetime")
     */
    public $endDateInsured;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $loanNumber;

    /**
     * @ORM\Column(type="integer")
     */
    public $representative;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $representativeName;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $representativeRole;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $representativeEmail;

    /**
     * @ORM\Column(type="string", length=255)
     */
    public $representativePhone;

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

    public function getOrganisation()
    {
        return $this->organisation;
    }

    public function setOrganisation($organisation)
    {
        $this->organisation = $organisation;
    }

    public function getOrganisationName()
    {
        return $this->organisationName;
    }

    public function setOrganisationName($organisationName)
    {
        $this->organisationName = $organisationName;
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

    public function getUrl()
    {
        return $this->url;
    }

    public function setUrl($url)
    {
        $this->url = $url;
    }

    public function getStartDate()
    {
        return $this->startDate;
    }

    public function setStartDate($startDate)
    {
        $this->startDate = $startDate;
    }

    public function getEndDate()
    {
        return $this->endDate;
    }

    public function setEndDate($endDate)
    {
        $this->endDate = $endDate;
    }

    public function getStartDateInsured()
    {
        return $this->startDateInsured;
    }

    public function setStartDateInsured($startDateInsured)
    {
        $this->startDateInsured = $startDateInsured;
    }

    public function getEndDateInsured()
    {
        return $this->endDateInsured;
    }

    public function setEndDateInsured($endDateInsured)
    {
        $this->endDateInsured = $endDateInsured;
    }

    public function getLoanNumber()
    {
        return $this->loanNumber;
    }

    public function setLoanNumber($loanNumber)
    {
        $this->loanNumber = $loanNumber;
    }

    public function getRepresentative()
    {
        return $this->representative;
    }

    public function setRepresentative($representative)
    {
        $this->representative = $representative;
    }

    public function getRepresentativeName()
    {
        return $this->representativeName;
    }

    public function setRepresentativeName($representativeName)
    {
        $this->representativeName = $representativeName;
    }

    public function getRepresentativeRole()
    {
        return $this->representativeRole;
    }

    public function setRepresentativeRole($representativeRole)
    {
        $this->representativeRole = $representativeRole;
    }

    public function getRepresentativeEmail()
    {
        return $this->representativeEmail;
    }

    public function setRepresentativeEmail($representativeEmail)
    {
        $this->representativeEmail = $representativeEmail;
    }

    public function getRepresentativePhone()
    {
        return $this->representativePhone;
    }

    public function setRepresentativePhone($representativePhone)
    {
        $this->representativePhone = $representativePhone;
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
