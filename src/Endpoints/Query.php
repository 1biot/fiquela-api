<?php

namespace Api\Endpoints;

use Api;
use Api\Utils\Stopwatch;
use FQL\Exception\FileAlreadyExistsException;
use FQL\Exception\FileNotFoundException;
use FQL\Exception\InvalidFormatException;
use Nette\Schema\ValidationException;
use Nette\Utils;
use Slim\Exception;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Query extends Controller
{
    public const int DefaultLimit = 1000;

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        Stopwatch::start('query');
        try {
            $workspace = $this->getWorkspace($request);
            $data = $this->validateRequest($request, new Api\Schemas\Query);
            $file = $data['file'] ?? null;
            $refresh = $data['refresh'] ?? false;
            $query = $data['query'] ?? '';

            $result = $workspace->runQuery($query, $file, $refresh);

            if ($result->workspaceChanged && $result->intoSchema !== null) {
                $responseData = [
                    'query' => (string) $query,
                    'file' => $file,
                    'hash' => $result->hash,
                    'data' => [$result->intoSchema],
                    'elapsed' => round(Stopwatch::stop('query') * 1000, 2),
                    'pagination' => [
                        'page' => 1,
                        'pageCount' => 1,
                        'itemCount' => 1,
                        'itemsPerPage' => 1,
                        'offset' => 0,
                    ],
                    'workspaceChanged' => true,
                ];
                return $this->json($response, $responseData, 201);
            }

            $count = $result->query->execute()->count();

            $paginator = new Utils\Paginator;
            $paginator->setItemCount($count);
            $paginator->setPage((int) ($data['page'] ?? 1));
            $paginator->setItemsPerPage(min(self::DefaultLimit, $count, (int) ($data['limit'] ?? self::DefaultLimit)));

            if ($paginator->getPageCount() > 1) {
                $result->query->offset($paginator->getOffset())
                    ->limit($paginator->getItemsPerPage());
            }

            $responseData = [
                'query' => (string) $query,
                'file' => $file,
                'hash' => $result->hash,
                'data' => iterator_to_array($result->query->execute()->getIterator()),
                'elapsed' => round(Stopwatch::stop('query') * 1000, 2),
                'pagination' => [
                    'page' => $paginator->getPage(),
                    'pageCount' => $paginator->getPageCount(),
                    'itemCount' => $paginator->getItemCount(),
                    'itemsPerPage' => $paginator->getItemsPerPage(),
                    'offset' => $paginator->getOffset(),
                ],
            ];

            return $this->json($response, $responseData);
        } catch (ValidationException $e) {
            throw new Api\Exceptions\UnprocessableContentHttpException($request, previous: $e);
        } catch (Api\Exceptions\LintValidationException $e) {
            return $this->json(
                $response,
                ['error' => $e->getMessage(), 'issues' => $e->issues],
                422
            );
        } catch (\FQL\Sql\Parser\ParseException $e) {
            throw new Api\Exceptions\UnprocessableQueryHttpException($request, $e->getMessage(), $e);
        } catch (Api\Exceptions\IntoTopLevelValidationException $e) {
            throw new Api\Exceptions\UnprocessableQueryHttpException($request, $e->getMessage(), $e);
        } catch (FileAlreadyExistsException $e) {
            throw new Api\Exceptions\ConflictHttpException($request, 'INTO target file already exists.', $e);
        } catch (FileNotFoundException $e) {
            throw new Exception\HttpNotFoundException($request, previous: $e);
        } catch (InvalidFormatException $e) {
            throw new Exception\HttpBadRequestException($request, previous: $e);
        } catch (\Throwable $e) {
            throw new Exception\HttpInternalServerErrorException($request, previous: $e);
        }
    }
}
