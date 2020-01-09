<?php
/*
 * Spring Signage Ltd - http://www.springsignage.com
 * Copyright (C) 2015 Spring Signage Ltd
 * (DataSetData.php)
 */


namespace Xibo\Controller;

use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
//use Xibo\Entity\DataSetColumn;
use Slim\Views\Twig;
use Xibo\Exception\AccessDeniedException;
use Xibo\Exception\InvalidArgumentException;
use Xibo\Exception\NotFoundException;
use Xibo\Exception\XiboException;
use Xibo\Factory\DataSetFactory;
use Xibo\Factory\MediaFactory;
use Xibo\Helper\SanitizerService;
use Xibo\Service\ConfigServiceInterface;
use Xibo\Service\DateServiceInterface;
use Xibo\Service\LogServiceInterface;

/**
 * Class DataSetData
 * @package Xibo\Controller
 */
class DataSetData extends Base
{
    /** @var  DataSetFactory */
    private $dataSetFactory;

    /** @var  MediaFactory */
    private $mediaFactory;

    /**
     * Set common dependencies.
     * @param LogServiceInterface $log
     * @param SanitizerService $sanitizerService
     * @param \Xibo\Helper\ApplicationState $state
     * @param \Xibo\Entity\User $user
     * @param \Xibo\Service\HelpServiceInterface $help
     * @param DateServiceInterface $date
     * @param ConfigServiceInterface $config
     * @param DataSetFactory $dataSetFactory
     * @param MediaFactory $mediaFactory
     * @param Twig $view
     */
    public function __construct($log, $sanitizerService, $state, $user, $help, $date, $config, $dataSetFactory, $mediaFactory, Twig $view)
    {
        $this->setCommonDependencies($log, $sanitizerService, $state, $user, $help, $date, $config, $view);

        $this->dataSetFactory = $dataSetFactory;
        $this->mediaFactory = $mediaFactory;
    }

    /**
     * Display Page
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     */
    public function displayPage(Request $request, Response $response, $id)
    {
        $dataSet = $this->dataSetFactory->getById($id);

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }
        
        // Load data set
        $dataSet->load();

        $this->getState()->template = 'dataset-dataentry-page';
        $this->getState()->setData([
            'dataSet' => $dataSet
        ]);
        
        return $this->render($request, $response);
    }

    /**
     * Grid
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     * @SWG\Get(
     *  path="/dataset/data/{dataSetId}",
     *  operationId="dataSetData",
     *  tags={"dataset"},
     *  summary="DataSet Data",
     *  description="Get Data for DataSet",
     *  @SWG\Parameter(
     *      name="dataSetId",
     *      in="path",
     *      description="The DataSet ID",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation"
     *  )
     * )
     *
     */
    public function grid(Request $request, Response $response, $id)
    {
        $dataSet = $this->dataSetFactory->getById($id);
        $sanitizedParams = $this->getSanitizer($request->getParams());

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }
        
        $sorting = $this->gridRenderSort($request);

        if ($sorting != null) {
            $sorting = implode(',', $sorting);
        }
        
        // Filter criteria
        $filter = '';
        foreach ($dataSet->getColumn() as $column) {
            /* @var \Xibo\Entity\DataSetColumn $column */
            if ($column->dataSetColumnTypeId == 1) {
                if ($sanitizedParams->getString($column->heading) != null) {
                    $filter .= 'AND ' . $column->heading . ' LIKE \'%' . $sanitizedParams->getString($column->heading) . '%\' ';
                }
            }
        }
        $filter = trim($filter, 'AND');

        // Work out the limits
        $filter = $this->gridRenderFilter(['filter' => $request->getParam('filter', $filter)], $request);

        try {
            $data = $dataSet->getData([
                'order' => $sorting,
                'start' => $filter['start'],
                'size' => $filter['length'],
                'filter' => $filter['filter']
            ]);
        } catch (\Exception $e) {
            $data = ['exception' => __('Error getting DataSet data, failed with following message: ') . $e->getMessage()];
            $this->getLog()->error('Error getting DataSet data, failed with following message: ' . $e->getMessage());
            $this->getLog()->debug($e->getTraceAsString());
        }

        $this->getState()->template = 'grid';
        $this->getState()->setData($data);

        // Output the count of records for paging purposes
        if ($dataSet->countLast() != 0)
            $this->getState()->recordsTotal = $dataSet->countLast();

        // Set this dataSet as being active
        $dataSet->setActive();
        
        return $this->render($request, $response);
    }

    /**
     * Add Form
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     */
    public function addForm(Request $request, Response $response, $id)
    {
        $dataSet = $this->dataSetFactory->getById($id);

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }
        
        $dataSet->load();

        $this->getState()->template = 'dataset-data-form-add';
        $this->getState()->setData([
            'dataSet' => $dataSet
        ]);
        
        return $this->render($request, $response);
    }

    /**
     * Add
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     * @throws \Xibo\Exception\DuplicateEntityException
     * @SWG\Post(
     *  path="/dataset/data/{dataSetId}",
     *  operationId="dataSetDataAdd",
     *  tags={"dataset"},
     *  summary="Add Row",
     *  description="Add a row of Data to a DataSet",
     *  @SWG\Parameter(
     *      name="dataSetId",
     *      in="path",
     *      description="The DataSet ID",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="dataSetColumnId_ID",
     *      in="formData",
     *      description="Parameter for each dataSetColumnId in the DataSet",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=201,
     *      description="successful operation",
     *      @SWG\Header(
     *          header="Location",
     *          description="Location of the new record",
     *          type="string"
     *      )
     *  )
     * )
     *
     */
    public function add(Request $request, Response $response, $id)
    {
        $dataSet = $this->dataSetFactory->getById($id);
        $sanitizedParams = $this->getSanitizer($request->getParams());

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }

        $row = [];

        // Expect input for each value-column
        foreach ($dataSet->getColumn() as $column) {
            /* @var \Xibo\Entity\DataSetColumn $column */
            if ($column->dataSetColumnTypeId == 1) {

                // Sanitize accordingly
                if ($column->dataTypeId == 2) {
                    // Number
                    $value = $sanitizedParams->getDouble('dataSetColumnId_' . $column->dataSetColumnId);
                }
                else if ($column->dataTypeId == 3) {
                    // Date
                    $value = $this->getDate()->getLocalDate($sanitizedParams->getDate('dataSetColumnId_' . $column->dataSetColumnId));
                }
                else if ($column->dataTypeId == 5) {
                    // Media Id
                    $value = $sanitizedParams->getInt('dataSetColumnId_' . $column->dataSetColumnId);
                }
                else {
                    // String
                    $value = $sanitizedParams->getString('dataSetColumnId_' . $column->dataSetColumnId);
                }

                $row[$column->heading] = $value;
            } elseif ($column->dataSetColumnTypeId == 3) {
                throw new InvalidArgumentException(__('Cannot add new rows to remote dataSet'), 'dataSetColumnTypeId');
            }
        }

        // Use the data set object to add a row
        $rowId = $dataSet->addRow($row);


        // Save the dataSet
        $dataSet->save(['validate' => false, 'saveColumns' => false]);

        // Return
        $this->getState()->hydrate([
            'httpStatus' => 201,
            'message' => __('Added Row'),
            'id' => $rowId,
            'data' => [
                'id' => $rowId
            ]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Edit Form
     * @param Request $request
     * @param Response $response
     * @param $id
     * @param $rowId
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     */
    public function editForm(Request $request, Response $response, $id, $rowId)
    {
        $dataSet = $this->dataSetFactory->getById($id);

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }

        $dataSet->load();

        $row = $dataSet->getData(['id' => $rowId])[0];

        // Augment my row with any already selected library image
        foreach ($dataSet->getColumn() as $dataSetColumn) {
            if ($dataSetColumn->dataTypeId === 5) {
                // Add this image object to my row
                try {
                    if (isset($row[$dataSetColumn->heading])) {
                        $row['__images'][$dataSetColumn->dataSetColumnId] = $this->mediaFactory->getById($row[$dataSetColumn->heading]);
                    }
                } catch (NotFoundException $notFoundException) {
                    $this->getLog()->debug('DataSet ' . $id . ' references an image that no longer exists. ID is ' . $row[$dataSetColumn->heading]);
                }
            }
        }

        $this->getState()->template = 'dataset-data-form-edit';
        $this->getState()->setData([
            'dataSet' => $dataSet,
            'row' => $row
        ]);

        return $this->render($request, $response);
    }

    /**
     * Edit Row
     * @param Request $request
     * @param Response $response
     * @param $id
     * @param int $rowId
     *
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     * @throws \Xibo\Exception\DuplicateEntityException
     * @SWG\Put(
     *  path="/dataset/data/{dataSetId}/{rowId}",
     *  operationId="dataSetDataEdit",
     *  tags={"dataset"},
     *  summary="Edit Row",
     *  description="Edit a row of Data to a DataSet",
     *  @SWG\Parameter(
     *      name="dataSetId",
     *      in="path",
     *      description="The DataSet ID",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="rowId",
     *      in="path",
     *      description="The Row ID of the Data to Edit",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="dataSetColumnId_ID",
     *      in="formData",
     *      description="Parameter for each dataSetColumnId in the DataSet",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation"
     *  )
     * )
     *
     */
    public function edit(Request $request, Response $response, $id, $rowId)
    {
        $dataSet = $this->dataSetFactory->getById($id);
        $sanitizedParams = $this->getSanitizer($request->getParams());

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }

        $existingRow = $dataSet->getData(['id' => $rowId])[0];
        $row = [];

        // Expect input for each value-column
        foreach ($dataSet->getColumn() as $column) {
            /* @var \Xibo\Entity\DataSetColumn $column */
            $existingValue = $request->getParam($column->heading, null);

            if ($column->dataSetColumnTypeId == 1) {

                // Pull out the value
                $value = $request->getParam('dataSetColumnId_' . $column->dataSetColumnId, null);

                $this->getLog()->debug('Value is: ' . var_export($value, true) . ', existing value is ' . var_export($existingValue, true));

                // Sanitize accordingly
                if ($column->dataTypeId == 2) {
                    // Number
                    if ($value === null)
                        $value = $existingValue;

                    $value = $sanitizedParams->getDouble($value);
                }
                else if ($column->dataTypeId == 3) {
                    // Date
                    if ($value === null) {
                        // Keep it as it was
                        $value = $existingValue;
                    } else {
                        // Parse the new date and convert to a local date/time
                        $value = $this->getDate()->getLocalDate($this->getDate()->parse($value));
                    }
                }
                else if ($column->dataTypeId == 5) {
                    // Media Id
                    if (isset($value)) {
                        $value = $sanitizedParams->getInt($value);
                    } else {
                        $value = null;
                    }
                }
                else {
                    // String
                    if ($value === null)
                        $value = $existingValue;

                    $value = $sanitizedParams->getString($value);
                }

                $row[$column->heading] = $value;
            }
        }

        // Use the data set object to edit a row
        if ($row != [])
            $dataSet->editRow($rowId, $row);
        else
            throw new InvalidArgumentException(__('Cannot edit data of remote columns'), 'dataSetColumnTypeId');

        // Save the dataSet
        $dataSet->save(['validate' => false, 'saveColumns' => false]);

        // Return
        $this->getState()->hydrate([
            'message' => __('Edited Row'),
            'id' => $rowId,
            'data' => [
                'id' => $rowId
            ]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Delete Form
     * @param Request $request
     * @param Response $response
     * @param $id
     * @param int $rowId
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     */
    public function deleteForm(Request $request, Response $response, $id, $rowId)
    {
        $dataSet = $this->dataSetFactory->getById($id);

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }

        $dataSet->load();

        $this->getState()->template = 'dataset-data-form-delete';
        $this->getState()->setData([
            'dataSet' => $dataSet,
            'row' => $dataSet->getData(['id' => $rowId])[0]
        ]);

        return $this->render($request, $response);
    }

    /**
     * Delete Row
     * @param Request $request
     * @param Response $response
     * @param $id
     * @param $rowId
     *
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \Xibo\Exception\ConfigurationException
     * @throws \Xibo\Exception\ControllerNotImplemented
     * @throws \Xibo\Exception\DuplicateEntityException
     * @SWG\Delete(
     *  path="/dataset/data/{dataSetId}/{rowId}",
     *  operationId="dataSetDataDelete",
     *  tags={"dataset"},
     *  summary="Delete Row",
     *  description="Delete a row of Data to a DataSet",
     *  @SWG\Parameter(
     *      name="dataSetId",
     *      in="path",
     *      description="The DataSet ID",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="rowId",
     *      in="path",
     *      description="The Row ID of the Data to Delete",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Response(
     *      response=204,
     *      description="successful operation"
     *  )
     * )
     */
    public function delete(Request $request, Response $response, $id, $rowId)
    {
        $dataSet = $this->dataSetFactory->getById($id);

        if (!$this->getUser($request)->checkEditable($dataSet)) {
            throw new AccessDeniedException();
        }

        if (empty($dataSet->getData(['id' => $rowId])[0])) {
            throw new NotFoundException(__('row not found'), 'dataset');
        }

        // Delete the row
        $dataSet->deleteRow($rowId);

        // Save the dataSet
        $dataSet->save(['validate' => false, 'saveColumns' => false]);

        // Return
        $this->getState()->hydrate([
            'httpStatus' => 204,
            'message' => __('Deleted Row'),
            'id' => $rowId
        ]);

        return $this->render($request, $response);
    }
}