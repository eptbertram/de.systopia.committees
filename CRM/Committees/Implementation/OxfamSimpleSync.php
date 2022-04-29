<?php
/*-------------------------------------------------------+
| SYSTOPIA Committee Framework                           |
| Copyright (C) 2021-2022 SYSTOPIA                       |
| Author: B. Endres (endres@systopia.de)                 |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Committees_ExtensionUtil as E;

/**
 * Syncer for KuerschnerCsvImporter parliamentary committe model
 */
class CRM_Committees_Implementation_OxfamSimpleSync extends CRM_Committees_Plugin_Syncer
{
    use CRM_Committees_Tools_IdTrackerTrait;
    use CRM_Committees_Tools_ContactGroupTrait;

    /** @var string committee.type value for parliamentary committee (Ausschuss) */
    const COMMITTEE_TYPE_PARLIAMENTARY_COMMITTEE = 'parliamentary_committee';

    /** @var string committee.type value for parliamentary group (Fraktion) */
    const COMMITTEE_TYPE_PARLIAMENTARY_GROUP = 'parliamentary_group';

    /** @var string committee.type value for parliament */
    const COMMITTEE_TYPE_PARLIAMENT = 'parliament';

    const ID_TRACKER_TYPE = 'kuerschners';
    const ID_TRACKER_PREFIX = 'KUE-';
    const ID_TRACKER_PREFIX_PARLIAMENT = 'PARLIAMENT-';     // todo: adjustment needed, if same importer should be used for other parliaments
    const ID_TRACKER_PREFIX_COMMITTEE = 'BUND-AUSSCHUSS-';  // todo: adjustment needed, if same importer should be used for other parliaments
    const ID_TRACKER_PREFIX_FRAKTION = 'BUND-FRAKTION-';    // todo: adjustment needed, if same importer should be used for other parliaments
    const CONTACT_SOURCE = 'kuerschners_MdB_';              // todo: adjustment needed, if same importer should be used for other parliaments
    const COMMITTE_SUBTYPE_NAME = 'Committee';
    const COMMITTE_SUBTYPE_LABEL = 'Gremium';

    /**
     * This function will be called *before* the plugin will do it's work.
     *
     * If your implementation has any external dependencies, you should
     *  register those with the registerMissingRequirement function.
     *
     */
    public function checkRequirements()
    {
        // we need the identity tracker
        $this->checkIdTrackerRequirements($this);
    }

    /**
     * Sync the given model into the CiviCRM
     *
     * @param CRM_Committees_Model_Model $model
     *   the model to be synced to this CiviCRM
     *
     * @param boolean $transaction
     *   should the sync happen in a single transaction
     *
     * @return boolean
     */
    public function syncModel($model, $transaction = false)
    {
        // to be sure, disable the execution time limit
        ini_set('max_execution_time', '0');

        // first, make sure some stuff is there:
        // 1. ID Tracker
        $this->registerIDTrackerType(self::ID_TRACKER_TYPE, "Kürschners");

        // 2. Contact group 'Lobby-Kontakte'
        $lobby_contact_group_id = $this->getOrCreateContactGroup(['title' => E::ts('Lobby-Kontakte')]);

        // 3. make sure parliament is there
        $this->getParliamentContactID($model);

        // 4. Add a direct parliament -> member-of-parliament relationship (not in the model)
        // see https://projekte.systopia.de/issues/17336#Problemf%C3%A4lle
        $parliament_name = $this->getParliamentName($model);
        $parliament_identifier = CRM_Committees_Implementation_KuerschnerCsvImporter::getCommitteeID($parliament_name);
        $model->addCommittee([
             'name' => $parliament_name,
             'id'   => $parliament_identifier,
             'type' => self::COMMITTEE_TYPE_PARLIAMENT,
        ]);
        foreach ($model->getAllPersons() as $person) {
            /** @var $person CRM_Committees_Model_Person */
            $model->addCommitteeMembership(
                [
                    'contact_id' => $person->getID(),
                    'committee_id' => $parliament_identifier,
                    'committee_name' => $parliament_name,
                    'type' => self::COMMITTEE_TYPE_PARLIAMENT,
                    'role' => 'Mitglied',
                ]
            );
        }

        /**************************************
         **        RUN SYNCHRONISATION       **
         **************************************/

        /** @var $present_model CRM_Committees_Model_Model this model will contain the data currently present in the DB  */
        $present_model = new CRM_Committees_Model_Model();

        /**********************************************
         **               SYNC COMMITTEES            **
         **********************************************/
        // first: apply custom adjustments to the committees
        foreach ($model->getAllCommittees() as $committee) {
            if ($committee->getAttribute('type') == self::COMMITTEE_TYPE_PARLIAMENTARY_GROUP) {
                $new_committee_name = $this->getFraktionName($committee->getAttribute('name'));
                $committee->setAttribute('name', $new_committee_name);
            }
        }

        // now extract current committees and run the diff
        $this->extractCurrentCommittees($model, $present_model);
        [$new_committees, $changed_committees, $obsolete_committees] = $present_model->diffCommittees($model, ['contact_id']);
        if ($new_committees) {
            foreach ($new_committees as $new_committee) {
                /** @var CRM_Committees_Model_Committee $new_committee */
                $committee_name = $new_committee->getAttribute('name');
                if ($new_committee->getAttribute('type') == self::COMMITTEE_TYPE_PARLIAMENTARY_GROUP) {
                    $tracker_prefix = self::ID_TRACKER_PREFIX_FRAKTION;
                } elseif ($new_committee->getAttribute('type') == self::COMMITTEE_TYPE_PARLIAMENT) {
                    $tracker_prefix = self::ID_TRACKER_PREFIX_PARLIAMENT;
                } else {
                    $tracker_prefix = self::ID_TRACKER_PREFIX_COMMITTEE;
                }
                $result = $this->callApi3('Contact', 'create', [
                    'organization_name' => $committee_name,
                    'contact_sub_type' => $this->getCommitteeSubType(),
                    'contact_type' => 'Organization'
                ]);
                $this->setIDTContactID($new_committee->getID(), $result['id'], self::ID_TRACKER_TYPE, $tracker_prefix);
                $this->addContactToGroup($result['id'], $lobby_contact_group_id, true);
                $new_committee->setAttribute('contact_id', $result['id']);
                $present_model->addCommittee($new_committee->getData());
            }
            $this->log(count($new_committees) . " new committees created.");
        }
        // add warnings:
        if ($changed_committees) {
            $this->log("There are changes to some committees, but these currently won't be applied.");
        }
        if ($obsolete_committees) {
            $this->log("There are obsolete committees, but they will not be removed.");
        }

        /**********************************************
         **           SYNC BASE CONTACTS            **
         **********************************************/
        $this->log("Syncing " . count($model->getAllPersons()) . " data sets...");

        // first: apply custom adjustments to the persons
        foreach ($model->getAllPersons() as $person) {
            /** @var CRM_Committees_Model_Person $person */
            $person_data = $person->getData();
            $person->setAttribute('gender_id', $this->getGenderId($person_data));
            $person->setAttribute('suffix_id', $this->getSuffixId($person_data));
            $person->setAttribute('prefix_id', $this->getPrefixId($person_data));
        }

        // then compare to current model and apply changes
        $this->extractCurrentContacts($model, $present_model);
        [$new_persons, $changed_persons, $obsolete_persons] = $present_model->diffPersons($model, ['contact_id', 'formal_title']);

        // create missing contacts
        foreach ($new_persons as $new_person) {
            /** @var CRM_Committees_Model_Person $new_person */
            $person_data = $new_person->getDataWithout(['id']);
            $person_data['contact_type'] = $this->getContactType($person_data);
            $person_data['contact_sub_type'] = $this->getContactSubType($person_data);
            $person_data['source'] = self::CONTACT_SOURCE . date('Y');
            $result = $this->callApi3('Contact', 'create', $person_data);

            // contact post-processing
            $this->setIDTContactID($new_person->getID(), $result['id'], self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX);
            $this->addContactToGroup($result['id'], $lobby_contact_group_id, true);
            $new_person->setAttribute('contact_id', $result['id']);
            $present_model->addPerson($new_person->getData());

            $this->log("Kürschner Contact [{$new_person->getID()}] created with CiviCRM-ID [{$result['id']}].");
        }
        if (!$new_persons) {
            $this->log("No new contacts detected in import data.");
        }
        // apply changes to existing contacts
        foreach ($changed_persons as $current_person) {
            /** @var CRM_Committees_Model_Person $current_person */
            $person_update = [
                'id' => $this->getIDTContactID($current_person->getID(), self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX)
            ];
            $differing_attributes = explode(',', $current_person->getAttribute('differing_attributes'));
            $changed_person = $model->getPerson($current_person->getID());
            foreach ($differing_attributes as $differing_attribute) {
                $person_update[$differing_attribute] = $changed_person->getAttribute($differing_attribute);
            }
            $result = $this->callApi3('Contact', 'create', $person_update);
            $this->log("Kürschner Contact [{$current_person->getID()}] (CID [{$person_update['id']}]) updated, changed: " . $current_person->getAttribute('differing_attributes'));
        }

        // note obsolete contacts
        if (!empty($obsolete_persons)) {
            $obsolete_person_count = count($obsolete_persons);
            $this->log("There are {$obsolete_person_count} obsolete persons, but they will not be removed.");
        }

        /**********************************************
         **           SYNC CONTACT EMAILS            **
         **********************************************/
        $this->extractCurrentDetails($model, $present_model, 'email');
        [$new_emails, $changed_emails, $obsolete_emails] = $present_model->diffEmails($model, ['location_type']);
        foreach ($new_emails as $email) {
            /** @var CRM_Committees_Model_Email $email */
            $email_data = $email->getData();
            $email_data['location_type_id'] = $this->getAddressLocationType(CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG);
            $email_data['is_primary'] = 1;
            $person = $email->getContact($present_model);
            if ($person) {
                $email_data['contact_id'] = $person->getAttribute('contact_id');
                $this->callApi3('Email', 'create', $email_data);
                $this->log("Added email '{$email_data['email']} to contact [{$email_data['contact_id']}]");
            }
        }
        if (!$new_emails) {
            $this->log("No new emails detected in import data.");
        }
        if ($changed_emails) {
            $changed_emails_count = count($changed_emails);
            $this->log("Some attributes have changed for {$changed_emails_count}, be we won't adjust that.");
        }
        if ($obsolete_emails) {
            $obsolete_emails_count = count($obsolete_emails);
            $this->log("{$obsolete_emails_count} emails are not listed in input, but won't delete.");
        }

        /**********************************************
         **           SYNC CONTACT PHONES            **
         **********************************************/
        $this->extractCurrentDetails($model, $present_model, 'phone');
        [$new_phones, $changed_phones, $obsolete_phones] = $present_model->diffPhones($model, ['location_type']);
        foreach ($new_phones as $phone) {
            /** @var CRM_Committees_Model_Phone $phone */
            $phone_data = $phone->getData();
            $phone_data['location_type_id'] = $this->getAddressLocationType(CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG);
            $phone_data['is_primary'] = 1;
            $phone_data['phone_type_id'] = $this->getPhoneTypeId($phone_data);
            $person = $phone->getContact($present_model);
            if ($person) {
                $phone_data['contact_id'] = $person->getAttribute('contact_id');
                $this->callApi3('Phone', 'create', $phone_data);
                $this->log("Added phone '{$phone_data['phone']} to contact [{$phone_data['contact_id']}]");
            }
        }
        if (!$new_phones) {
            $this->log("No new phones detected in import data.");
        }
        if ($changed_phones) {
            $changed_phones_count = count($changed_phones);
            $this->log("Some attributes have changed for {$changed_phones_count}, be we won't adjust that.");
        }
        if ($obsolete_phones) {
            $obsolete_phones_count = count($obsolete_phones);
            $this->log("{$obsolete_phones_count} phones are not listed in input, but won't delete.");
        }

        /**********************************************
         **           SYNC CONTACT ADDRESSES         **
         **********************************************/
        // first: apply custom adjustments to the addresses
        foreach ($model->getAllAddresses() as $address) {
            /** @var CRM_Committees_Model_Address $address */
            if ($address->getAttribute('location_type') != CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG) {
                $address->removeFromModel();
            }
        }

        $this->extractCurrentDetails($model, $present_model, 'address');
        [$new_addresses, $changed_addresses, $obsolete_addresses] = $present_model->diffAddresses($model, ['location_type', 'organization_name']);
        $unwanted_relationship_counter = 0;
        foreach ($new_addresses as $address) {
            /** @var \CRM_Committees_Model_Address $address */
            $address_data = $address->getData();
            $address_data['location_type_id'] = $this->getAddressLocationType(CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG);
            $address_data['is_primary'] = 1;
            $address_data['master_id'] = $this->getParliamentAddressID($model);
            $person = $address->getContact($present_model);
            if ($person) {
                $address_data['contact_id'] = $person->getAttribute('contact_id');
                $last_relationship_id = (int) CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM civicrm_relationship;");
                $this->callApi3('Address', 'create', $address_data);
                $this->log("Added address '{$address_data['street_address']}/{$address_data['postal_code']} {$address_data['city']}' to contact [{$address_data['contact_id']}]");

                // check if the shared address (master_id) has created a relationship, and delete it (not wanted)
                $new_relationship_id = (int) CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM civicrm_relationship;");
                if ($new_relationship_id > $last_relationship_id) {
                    // delete it (those?)
                    $unwanted_relationships = CRM_Core_DAO::executeQuery("
                        SELECT relationship.id AS relationship_id  
                        FROM civicrm_relationship relationship
                        WHERE relationship.id > %1
                          AND relationship.contact_id_a = %2", [
                            1 => [$last_relationship_id, 'Integer'],
                            2 => [$person->getAttribute('contact_id'), 'Integer'],
                        ]);
                    while ($unwanted_relationships->fetch()) {
                        $this->callApi3('Relationship', 'delete', ['id' => $unwanted_relationships->relationship_id]);
                        $unwanted_relationship_counter++;
                    }
                }
            }
        }
        if ($unwanted_relationship_counter) {
            $this->log("{$unwanted_relationship_counter} unwanted relationships (employee of) have been deleted");
        }
        if (!$new_addresses) {
            $this->log("No new addresses detected in import data.");
        }
        if ($changed_addresses) {
            $changed_addresses_count = count($changed_addresses);
            $this->log("Some attributes have changed for {$changed_addresses_count}, be we won't adjust that.");
        }
        if ($obsolete_addresses) {
            $obsolete_addresses_count = count($obsolete_addresses);
            $this->log("{$obsolete_addresses_count} addresses are not listed in input, but won't delete.");
        }

        /**********************************************
         **        SYNC COMMITTEE MEMBERSHIPS        **
         **********************************************/
        $this->addCurrentMemberships($model, $present_model);
        //$this->log(count($present_model->getAllMemberships()) . " existing committee memberships identified in CiviCRM.");

        $ignore_attributes = ['committee_name', 'relationship_id']; // todo: fine-tune
        [$new_memberships, $changed_memberships, $obsolete_memberships] = $present_model->diffMemberships($model, $ignore_attributes);
        // first: disable absent (deleted)
        foreach ($obsolete_memberships as $membership) {
            /** @var CRM_Committees_Model_Membership $membership */
            // this membership needs to be ended/deactivated
            $relationship_type_id = $membership->getAttribute('relationship_id');
            $this->callApi3('Relationship', 'create', [
                'id' => $relationship_type_id,
                'is_active' => 0,
            ]);
            $this->log("Disabled obsolete committee membership [{$membership->getAttribute('relationship_id')}].");
        }

        // CREATE the new ones
        foreach ($new_memberships as $new_membership) {
            /** @var CRM_Committees_Model_Membership $new_membership */
            $person_civicrm_id = $this->getIDTContactID($new_membership->getPerson()->getID(), self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX);
            switch ($new_membership->getCommittee()->getAttribute('type')) {
                case CRM_Committees_Implementation_KuerschnerCsvImporter::COMMITTEE_TYPE_PARLIAMENTARY_COMMITTEE:
                    $tracker_prefix = self::ID_TRACKER_PREFIX_COMMITTEE;
                    break;

                case self::COMMITTEE_TYPE_PARLIAMENT:
                    $tracker_prefix = self::ID_TRACKER_PREFIX_PARLIAMENT;
                    break;

                default:
                case CRM_Committees_Implementation_KuerschnerCsvImporter::COMMITTEE_TYPE_PARLIAMENTARY_GROUP:
                    $tracker_prefix = self::ID_TRACKER_PREFIX_FRAKTION;
                    break;
            }
            $committee_id = $this->getIDTContactID($new_membership->getCommittee()->getID(), self::ID_TRACKER_TYPE, $tracker_prefix);
            //$committee_id_to_contact_id[$new_membership->getCommittee()->getID()];
            $relationship_type_id = $this->getRelationshipTypeIdForMembership($new_membership);
            $this->callApi3('Relationship', 'create', [
                'contact_id_a' => $person_civicrm_id,
                'contact_id_b' => $committee_id,
                'relationship_type_id' => $relationship_type_id,
                'is_active' => 1,
                //'description' => $new_membership->getAttribute('role'),
            ]);
            $this->log("Added new committee membership [{$person_civicrm_id}]<->[{$committee_id}].");
        }
        $new_count = count($new_memberships);
        $this->log("{$new_count} new committee memberships created.");

        // UPDATE the existing ones (if necessary)
        foreach ($changed_memberships as $changed_membership) {
            /** @var CRM_Committees_Model_Membership $changed_membership */
            // Are there any changes that we can apply on this level? Since we dropped the 'description' attribute
            // (see https://projekte.systopia.de/issues/17336#note-23 item 5), everything is a different relationship.
            $this->log("Skipped minor chang for committee membership [{$changed_membership['id']}].");

            /* $this->callApi3('Relationship', 'create', [
                'id' => $changed_membership['id'],
                //'description' => $changed_membership->getAttribute('role'),
            ]);
            $this->log("Updated committee membership [{$changed_membership['id']}]."); */
        }

        // THAT'S IT, WE'RE DONE
        $this->log("If you're using this free module, send some grateful thoughts to OXFAM Germany.");
    }

    /**
     * Extract the current committee memberships and add to the present_model
     *
     * @param CRM_Committees_Model_Model $requested_model
     *   the model to be synced to this CiviCRM
     *
     * @param CRM_Committees_Model_Model $present_model
     *   a model to add the current memberships to, as extracted from the DB
     */
    protected function addCurrentMemberships($requested_model, $present_model)
    {
        // get some basic data
        $role2relationship_type = $this->getRoleToRelationshipTypeIdMapping();
        $relationship_type_ids = array_unique(array_values($role2relationship_type));

        // get the current committees
        $parliament_ids = [$this->getParliamentContactID($requested_model)];
        $fraction_ids = array_keys($this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_FRAKTION));
        $committee_ids = array_keys($this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_COMMITTEE));
        $all_committee_ids = array_merge($parliament_ids, $fraction_ids, $committee_ids);

        // get the current memberships of these committees
        $committee_query = civicrm_api3('Relationship', 'get', [
            'option.limit' => 0,
            'relationship_type_id' => ['IN' => $relationship_type_ids],
            //'is_active' => 1, // also find inactive ones, otherwise we get issues with duplicates
            'contact_id_b' => ['IN' => $all_committee_ids]
        ]);

        // extract existing committee memberships
        $contactID_2_trackerIDs = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX);
        $committee_committee_ID_2_trackerIDs = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_COMMITTEE);
        $committee_fraktion_ID_2_trackerIDs = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_FRAKTION);
        $parliament_id = self::getParliamentContactID($requested_model);
        $parliament_identifier = reset($this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_PARLIAMENT)[$parliament_id]);
        foreach ($committee_query['values'] as $committee_relationship) {
            // identify the committee type
            if ($committee_relationship['contact_id_b'] == $parliament_id) {
                // this is the membership with the parliament itself
                $committee_id = substr($parliament_identifier, strlen(self::ID_TRACKER_PREFIX_PARLIAMENT));
                $committee_type = self::COMMITTEE_TYPE_PARLIAMENT;
            } elseif (isset($committee_committee_ID_2_trackerIDs[$committee_relationship['contact_id_b']])) {
                // this is a committee (ausschuss)
                $committee_id = substr(reset($committee_committee_ID_2_trackerIDs[$committee_relationship['contact_id_b']]), strlen(self::ID_TRACKER_PREFIX_COMMITTEE));
                $committee_type = CRM_Committees_Implementation_KuerschnerCsvImporter::COMMITTEE_TYPE_PARLIAMENTARY_COMMITTEE;
                // this must be a parliamentary group (fraktion)
            } else {
                $committee_id = substr(reset($committee_fraktion_ID_2_trackerIDs[$committee_relationship['contact_id_b']]), strlen(self::ID_TRACKER_PREFIX_FRAKTION));
                $committee_type = CRM_Committees_Implementation_KuerschnerCsvImporter::COMMITTEE_TYPE_PARLIAMENTARY_GROUP;
            }
            $committee = $requested_model->getCommittee($committee_id);
            if (!$committee) {
                $this->logError("Committee [{$committee_id}] was referenced but not found in the mdoel.");
                continue;
            }

            // identify the contact
            $contact_id = $committee_relationship['contact_id_a'];
            $person_ids = $contactID_2_trackerIDs[$contact_id] ?? [];
            foreach ($person_ids as $person_id) {
                $present_model->addCommitteeMembership([
                       'contact_id'      => substr($person_id, strlen(self::ID_TRACKER_PREFIX)),
                       'committee_id'    => $committee_id,
                       'committee_name'  => $committee->getAttribute('name'),
                       'type'            => $committee_type,
                       'role'            => $committee_relationship['description'] ?? '',
                       'relationship_id' => $committee_relationship['id'],
                   ]);
            }
        }

        return $present_model;
    }



    /*****************************************************************************
     **                        PULL CURRENT DATA FOR SYNC                       **
     **         OVERWRITE THESE METHODS TO ADJUST TO YOUR DATA MODEL            **
     *****************************************************************************/

    /**
     * Get the current committees as a partial model
     *
     * @param CRM_Committees_Model_Model $requested_model
     *   the model to be synced to this CiviCRM
     *
     * @param CRM_Committees_Model_Model $present_model
     *   a model to add the current committees to, as extracted from the DB
     */
    protected function extractCurrentCommittees($requested_model, $present_model)
    {
        // add existing committees
        $existing_committees = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_COMMITTEE);
        if ($existing_committees) {
            $committees_found = $this->callApi3('Contact', 'get', [
                'contact_type' => 'Organization',
                'id' => ['IN' => array_keys($existing_committees)],
                'return' => 'id,organization_name',
                'option.limit' => 0,
            ]);
            foreach ($committees_found['values'] as $committee_found) {
                $present_committee_id = $existing_committees[$committee_found['id']][0];
                $present_model->addCommittee([
                     'name'       => $committee_found['organization_name'],
                     'type'       => self::COMMITTEE_TYPE_PARLIAMENTARY_COMMITTEE,
                     'id'         => substr($present_committee_id, strlen(self::ID_TRACKER_PREFIX_COMMITTEE)),
                     'contact_id' => $committee_found['id'],
                 ]);
            }
        }

        // add fractions
        $existing_fractions = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_FRAKTION);
        if ($existing_fractions) {
            $fractions_found = $this->callApi3('Contact', 'get', [
                'contact_type' => 'Organization',
                'id' => ['IN' => array_keys($existing_fractions)],
                'return' => 'id,organization_name',
                'option.limit' => 0,
            ]);
            foreach ($fractions_found['values'] as $fraction_found) {
                $present_fraction_id = $existing_fractions[$fraction_found['id']][0];
                $present_model->addCommittee([
                     'name'       => $fraction_found['organization_name'],
                     'type'       => self::COMMITTEE_TYPE_PARLIAMENTARY_GROUP,
                     'id'         => substr($present_fraction_id, strlen(self::ID_TRACKER_PREFIX_FRAKTION)),
                     'contact_id' => $fraction_found['id'],
                 ]);
            }
        }

        // add parliament
        $parliament_name = $this->getParliamentName($requested_model);
        $parliament_identifier = CRM_Committees_Implementation_KuerschnerCsvImporter::getCommitteeID($parliament_name);
        $present_model->addCommittee([
             'name'       => $parliament_name,
             'type'       => self::COMMITTEE_TYPE_PARLIAMENT,
             'id'         => $parliament_identifier,
             'contact_id' => $this->getParliamentContactID($requested_model)
         ]);
    }


    /**
     * Extract the currently imported contacts from CiviCRM and add them to the 'present model'
     *
     * @param CRM_Committees_Model_Model $requested_model
     *   the model to be synced to this CiviCRM
     *
     * @param CRM_Committees_Model_Model $present_model
     *   a model to add the current contacts to, as extracted from the DB
     */
    protected function extractCurrentContacts($requested_model, $present_model)
    {
        // add existing contacts
        $existing_contacts = $this->getContactIDtoTids(self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX);
        if ($existing_contacts) {
            $contacts_found = $this->callApi3('Contact', 'get', [
                'contact_type' => 'Individual',
                'id' => ['IN' => array_keys($existing_contacts)],
                'return' => 'id,contact_id,first_name,last_name,gender_id,prefix_id,suffix_id',
                'option.limit' => 0,
            ]);
            foreach ($contacts_found['values'] as $contact_found) {
                $present_contact_id = $existing_contacts[$contact_found['id']][0];
                $present_model->addPerson([
                      'id'           => substr($present_contact_id, strlen(self::ID_TRACKER_PREFIX)),
                      'contact_id'   => $contact_found['id'],
                      'first_name'   => $contact_found['first_name'],
                      'last_name'    => $contact_found['last_name'],
                      'gender_id'    => $contact_found['gender_id'],
                      'prefix_id'    => $contact_found['prefix_id'],
                      'suffix_id'    => $contact_found['suffix_id'],
                  ]);
            }
        }
    }

    /**
     * Extract the currently imported contacts from the CiviCRMs and add them to the 'present model'
     *
     * @param CRM_Committees_Model_Model $requested_model
     *   the model to be synced to this CiviCRM
     *
     * @param CRM_Committees_Model_Model $present_model
     *   a model to add the current contacts to, as extracted from the DB
     *
     * @param string $type
     *   phone, email or address
     */
    protected function extractCurrentDetails($requested_model, $present_model, $type)
    {
        // some basic configurations for the different types
        $load_attributes = [
            'email' => ['contact_id', 'email', 'location_type_id'],
            'phone' => ['contact_id', 'phone', 'location_type_id', 'phone_type_id', 'phone_numeric'],
            'address' => ['contact_id', 'street_address', 'postal_code', 'city', 'location_type_id'],
        ];
        $copy_attributes = [
            'email' => ['email'],
            'phone' => ['phone', 'phone_numeric'],
            'address' => ['street_address', 'postal_code', 'city', 'supplemental_address_1', 'supplemental_address_2', 'supplemental_address_3'],
        ];

        // check with all known CiviCRM contacts
        $contact_id_to_person_id = [];
        foreach ($present_model->getAllPersons() as $person) {
            /** @var CRM_Committees_Model_Person $person */
            $contact_id = (int) $person->getAttribute('contact_id');
            if ($contact_id) {
                $contact_id_to_person_id[$contact_id] = $person->getID();
            }
        }

        // stop here if there's no known contacts
        if (empty($contact_id_to_person_id)) {
            return [];
        }

        // load the given attributes
        $existing_details = $this->callApi3($type, 'get', [
            'contact_id' => ['IN' => array_keys($contact_id_to_person_id)],
            'return' => implode(',', $load_attributes[$type]),
            'option.limit' => 0,
        ]);

        // strip duplicates
        $data_by_id = [];
        foreach ($existing_details['values'] as $detail) {
            $person_id = $contact_id_to_person_id[$detail['contact_id']];
            $data = ['contact_id' => $person_id];
            foreach ($copy_attributes[$type] as $attribute) {
                $data[$attribute] = $detail[$attribute] ?? '';
            }
            $key = implode('##', $data);
            $data_by_id[$key] = $data;
        }

        // finally, add all to the model
        foreach ($data_by_id as $data) {
            switch ($type) {
                case 'phone':
                    $present_model->addPhone($data);
                    break;
                case 'email':
                    $present_model->addEmail($data);
                    break;
                case 'address':
                    $present_model->addAddress($data);
                    break;
                default:
                    throw new Exception("Unknown type {$type} for extractCurrentDetails function.");
            }
        }
    }


    /*****************************************************************************
     **                            DETAILS CUSTOMISATION                        **
     **         OVERWRITE THESE METHODS TO ADJUST TO YOUR DATA MODEL            **
     *****************************************************************************/

    /**
     * Get the right prefix ID for the given person data
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     */
    protected function getPrefixId($person_data)
    {
        if (empty($person_data['prefix_id'])) {
            return '';
        }

        $prefix_id = $person_data['prefix_id'];

        // map
        $mapping = [
            'Frau' => 'Frau',
            'Herrn' => 'Herr'
        ];
        if (isset($mapping[$prefix_id])) {
            $prefix_id = $mapping[$prefix_id];
        }

        $option_value = $this->getOrCreateOptionValue(['label' => $prefix_id], 'individual_prefix');
        return $option_value['value'];
    }

    /**
     * Get the right prefix string for the given CiviCRM prefix ID
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     */
    protected function getPrefixString($person_data)
    {
        if (empty($person_data['prefix_id'])) {
            return '';
        }

        $prefix_id = $person_data['prefix_id'];

        // map
        $mapping = [
            'Frau' => 'Frau',
            'Herrn' => 'Herr'
        ];
        if (isset($mapping[$prefix_id])) {
            $prefix_id = $mapping[$prefix_id];
        }

        $option_value = $this->getOrCreateOptionValue(['label' => $prefix_id], 'individual_prefix');
        return $option_value['value'];
    }


    /**
     * Get the right gender ID for the given person data
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     */
    protected function getGenderId(array $person_data)
    {
        if (empty($person_data['gender_id'])) {
            return '';
        }
        $gender_id = $person_data['gender_id'];

        // map
        $mapping = [
            'm' => 'männlich',
            'w' => 'weiblich'
        ];
        if (isset($mapping[$gender_id])) {
            $gender_id = $mapping[$gender_id];
        }

        $option_value = $this->getOrCreateOptionValue(['label' => $gender_id], 'gender');
        return $option_value['value'];
    }

    /**
     * Get the right gender ID for the given person data
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     */
    protected function getSuffixId(array $person_data)
    {
        if (empty($person_data['formal_title'])) {
            return '';
        }

        // no mapping, right?
        $suffix = $person_data['formal_title'];
        $option_value = $this->getOrCreateOptionValue(['label' => $suffix], 'individual_suffix');
        return $option_value['value'];
    }

    /**
     * Get the preferred contact type
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     *
     * @return string
     */
    protected function getContactType(array $person_data)
    {
        return 'Individual';
    }

    /**
     * Get the phone type for the given phone data
     *
     * @param array $phone_data
     *   list of the raw attributes coming from the model.
     *
     * @return integer
     */
    protected function getPhoneTypeId(array $phone_data)
    {
        // in this implementation, phone types are always 'landline'
        static $phone_type_id = null;
        if ($phone_type_id === null) {
            $landline_option_value = $this->getOrCreateOptionValue(
                ['name' => 'Phone', 'label' => E::ts('Phone')],
                'phone_type',
                'name');
            $phone_type_id = $landline_option_value['value'];
        }
        return $phone_type_id;
    }

    /**
     * Get the preferred contact type
     *
     * @param array $person_data
     *   list of the raw attributes coming from the model.
     *
     * @return string|null
     */
    protected function getContactSubType(array $person_data)
    {
        return null;
    }

    /**
     * Get the name of the currently imported parliament (CiviCRM Organization), e.g. "Bundestag"
     *
     * @param \CRM_Committees_Model_Model $model
     *   the model
     *
     * @return string name of the parliament
     */
    protected function getParliamentName($model)
    {
        static $parliament_name = null;
        if ($parliament_name === null) {
            foreach ($model->getAllAddresses() as $address) {
                /** @var \CRM_Committees_Model_Address $address */
                $location_type = $address->getAttribute('location_type');
                if ($location_type == CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG) {
                    $potential_parliament_name = $address->getAttribute('organization_name');
                    if (!empty($potential_parliament_name)) {
                        $parliament_name = $potential_parliament_name;
                        $this->log("Name of the parliament is '{$parliament_name}'");
                        return $parliament_name;
                    }
                }
            }
            throw new CiviCRM_API3_Exception("Couldn't identify parliament name!");
        }
        return $parliament_name;
    }


    /**
     * Get the ID of the currently imported parliament (CiviCRM Organization), e.g. "Bundestag"
     *
     * @param CRM_Committees_Model_Model $model
     *
     * @return int
     */
    protected function getParliamentContactID($model = null)
    {
        static $parliament_id = null;
        if (!$parliament_id) {
            $parliament_name = $this->getParliamentName($model);
            $parliament_identifier = CRM_Committees_Implementation_KuerschnerCsvImporter::getCommitteeID($parliament_name);
            $parliament_id = $this->getIDTContactID($parliament_identifier, self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_PARLIAMENT);
            if (!$parliament_id) {
                $parliament = $this->callApi3('Contact', 'create', [
                    'contact_type' => 'Organization',
                    'contact_sub_type' => $this->getParliamentSubType(),
                    'organization_name' => $parliament_name,
                ]);
                $parliament_id = $parliament['id'];
                $this->log("Created new organisation '{$parliament_name}' as it wasn't found.");
                $this->setIDTContactID($parliament_identifier, $parliament_id, self::ID_TRACKER_TYPE, self::ID_TRACKER_PREFIX_PARLIAMENT);
            }
        }
        return $parliament_id;
    }


    /**
     * Get the ID of the address of the "Bundestag"
     *
     * @param CRM_Committees_Model_Model $model
     *
     * @return int
     */
    protected function getParliamentAddressID($model)
    {
        static $parliament_address_id = null;
        if ($parliament_address_id === null) {
            // get or create parliament
            $parliament_name = $this->getParliamentName($model);
            $parliament_id = $this->getParliamentContactID($model);
            // get or create the address
            $addresses = civicrm_api3('Address', 'get', [
                'contact_id' => $parliament_id,
                'location_type_id' => 'Work',
                'is_primary' => 1,
                'option.limit' => 1,
            ]);
            if (empty($addresses['id'])) {
                // find a parliamentary address
                foreach ($model->getAllAddresses() as $address) {
                    /** @var CRM_Committees_Model_Address $address */
                    if ($address->getAttribute('location_type')
                        == CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG) {
                        // this should be the parliament's address
                        $parliament_address_data = [
                            'location_type_id' => 'Work',
                            'is_primary' => 1,
                            'contact_id' => $parliament_id,
                            'street_address' => $address->getAttribute('street_address'),
                            'postal_code' => $address->getAttribute('postal_code'),
                            'city' => $address->getAttribute('city'),
                            'supplemental_address_1' => $address->getAttribute('supplemental_address_1'),
                        ];
                        $addresses = civicrm_api3('Address', 'create', $parliament_address_data);
                        $this->log("Added new address to '{$parliament_name}'.");
                        break;
                    }
                }
            }
            $parliament_address_id = $addresses['id'];
        }

        return $parliament_address_id;
    }

    /**
     * Get the civicrm location type for the give kuerschner address type
     *
     * @param string $kuerschner_location_type
     *   should be one of 'Bundestag' / 'Regierung' / 'Wahlkreis'
     *
     * @return string|null
     *   return the location type name or ID, or null/empty to NOT import
     */
    protected function getAddressLocationType($kuerschner_location_type)
    {
        switch ($kuerschner_location_type) {
            case CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_BUNDESTAG:
                return 'Work';

            default:
            case CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_REGIERUNG:
            case CRM_Committees_Implementation_KuerschnerCsvImporter::LOCATION_TYPE_WAHLKREIS:
                // don't import
                return null;
        }
    }

    /**
     * Get the organization subtype for committees
     *
     * @return string
     *   the subtype name or null/empty string
     */
    protected function getCommitteeSubType()
    {
        static $was_created = false;
        if (!$was_created) {
            $this->createContactTypeIfNotExists(self::COMMITTE_SUBTYPE_NAME, self::COMMITTE_SUBTYPE_LABEL, 'Organization');
            $was_created = true;
        }
        return self::COMMITTE_SUBTYPE_NAME;
    }

    /**
     * Get the organization subtype for the parliament
     *
     * @return string
     *   the subtype name or null/empty string
     */
    protected function getParliamentSubType()
    {
        return $this->getCommitteeSubType();
    }

    /**
     * Get the relationship type ID to be used for the given membership
     *
     * @param CRM_Committees_Model_Membership $membership
     *   the membership
     *
     * @return integer
     *   relationship type ID
     */
    protected function getRelationshipTypeIdForMembership($membership) : int
    {
        $role = $membership->getAttribute('role');
        $role2relationship_type = $this->getRoleToRelationshipTypeIdMapping();
        if (isset($role2relationship_type[$role])) {
            return $role2relationship_type[$role];
        } else {
            if ($role) {
                $this->log("Warning: Couldn't map role '{$role}' to relationship type! Using member...");
            }
            return $role2relationship_type['Mitglied'];
        }
    }

    /**
     * Get the mapping of the role name to the relationship type id
     *
     * @return array
     *   role name to relationship type ID
     */
    protected function getRoleToRelationshipTypeIdMapping() : array
    {
        static $role2relationship_type = null;
        if ($role2relationship_type === null) {
            $role2relationship_type = [];

            // create relationship types
            $chairperson_relationship = $this->createRelationshipTypeIfNotExists(
                'is_committee_chairperson_of',
                'has_committee_chairperson',
                "Vorsitzende*r von",
                "Vorsitzende*r ist",
                'Individual',
                'Organization',
                null,
                $this->getCommitteeSubType(),
                ""
            );

            $deputy_chairperson_relationship = $this->createRelationshipTypeIfNotExists(
                'is_committee_deputy_chairperson_of',
                'has_committee_deputy_chairperson',
                "stellv. Vorsitzende*r von",
                "stellv. Vorsitzende*r ist",
                'Individual',
                'Organization',
                null,
                $this->getCommitteeSubType(),
                ""
            );

            $obperson_relationship = $this->createRelationshipTypeIfNotExists(
                'is_committee_obperson_of',
                'has_committee_obperson',
                "Obperson von",
                "Obperson ist",
                'Individual',
                'Organization',
                null,
                $this->getCommitteeSubType(),
                ""
            );

            $member_relationship = $this->createRelationshipTypeIfNotExists(
                'is_committee_member_of',
                'has_committee_member',
                "Mitglied von",
                "Mitglied ist",
                'Individual',
                'Organization',
                null,
                $this->getCommitteeSubType(),
                ""
            );

            $deputy_member_relationship = $this->createRelationshipTypeIfNotExists(
                'is_committee_deputy_member_of',
                'has_committee_deputy_member',
                "stellv. Mitglied von",
                "stellv. Mitglied ist",
                'Individual',
                'Organization',
                null,
                $this->getCommitteeSubType(),
                ""
            );

            // compile role mapping:
            $role2relationship_type['stellv. Mitglied'] = $deputy_member_relationship['id'];
            $role2relationship_type['Mitglied'] = $member_relationship['id'];
            $role2relationship_type['stellv. Mitglied'] = $member_relationship['id'];
            $role2relationship_type['Obmann'] = $obperson_relationship['id'];
            $role2relationship_type['Obfrau'] = $obperson_relationship['id'];
            $role2relationship_type['Obperson'] = $obperson_relationship['id'];
            $role2relationship_type['Vorsitzender'] = $chairperson_relationship['id'];
            $role2relationship_type['Vorsitzende'] = $chairperson_relationship['id'];
            $role2relationship_type['stellv. Vorsitzender'] = $deputy_chairperson_relationship['id'];
            $role2relationship_type['stellv. Vorsitzende'] = $deputy_chairperson_relationship['id'];
        }
        return $role2relationship_type;
    }

    /**
     * Generate a name for the Fraktion based on the party
     *
     * @param $party_name
     *
     * @return string
     */
    public function getFraktionName($party_name)
    {
        return E::ts("Fraktion %1 im Deutschen Bundestag", [1 => $party_name]);
    }
}