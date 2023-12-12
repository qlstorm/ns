<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    public function doOperation()
    {
        $data = (array)$this->getRequest('data');

        $resellerId = (int)$data['resellerId'];

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => ''
            ]
        ];

        if (!$resellerId) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';

            return $result;
        }

        if (!(int)$data['notificationType']) {
            throw new \Exception('Empty notificationType', 400);
        }

        $reseller = Seller::getById((int)$resellerId);

        if (!$reseller) {
            throw new \Exception('Seller not found!', 400);
        }

        $client = Contractor::getById((int)$data['clientId']);

        if (!$client || $client->type != Contractor::TYPE_CUSTOMER || $client->Seller->id != $resellerId) {
            throw new \Exception('сlient not found!', 400);
        }

        $cr = Employee::getById((int)$data['creatorId']);

        if (!$cr) {
            throw new \Exception('Creator not found!', 400);
        }

        $et = Employee::getById((int)$data['expertId']);

        if (!$et) {
            throw new \Exception('Expert not found!', 400);
        }

        $cFullName = $client->getFullName();

        if (!$cFullName) {
            $cFullName = $client->name;
        }

        $differences = '';

        if ($data['notificationType'] == self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        }
        
        if ($data['notificationType'] == self::TYPE_CHANGE && $data['differences']) {
            $diffDataParams = [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to'])
            ];

            $differences = __('PositionStatusHasChanged', $diffDataParams, $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => $data['complaintNumber'],
            'CREATOR_ID'         => (int)$data['creatorId'],
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)$data['expertId'],
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)$data['clientId'],
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => $data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => $data['agreementNumber'],
            'DATE'               => $data['date'],
            'DIFFERENCES'        => $differences
        ];

        foreach ($templateData as $key => $value) {
            if (!$value) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        $emailFrom = getResellerEmailFrom($resellerId);

        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        if ($emailFrom) {
            foreach ($emails as $email) {
                $messageParams = [
                    'emailFrom' => $emailFrom,
                    'emailTo'   => $email,
                    'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                    'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId)
                ];

                MessagesClient::sendMessage([$messageParams], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);

                $result['notificationEmployeeByEmail'] = true;
            }
        }

        if ($data['notificationType'] == self::TYPE_CHANGE && $data['differences']['to']) {
            if ($emailFrom && $client->email) {
                $messageParams = [
                    'emailFrom' => $emailFrom,
                    'emailTo'   => $client->email,
                    'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message'   => __('complaintClientEmailBody', $templateData, $resellerId)
                ];

                MessagesClient::sendMessage([$messageParams], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                
                $result['notificationClientByEmail'] = true;
            }

            if ($client->mobile) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
                
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }

                if ($error) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
